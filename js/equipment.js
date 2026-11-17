function loadEquipment() {
    try {
        const data = JSON.parse(localStorage.getItem(EQUIPMENT_STORAGE_KEY)) || {};

        Object.keys(data).forEach(slot => {
            const item = data[slot];

            if (!item || !item.id || !item.type_id) {
                delete data[slot];
            }
        });

        return data;
    } catch (e) {
        return {};
    }
}

function persistEquipment() {
    equipmentVersion++;
    storageSet(EQUIPMENT_STORAGE_KEY, JSON.stringify(equipment));
}

function saveEquipment() {
    persistEquipment();
    scheduleEquipmentCalculationRefresh(0);
}

function loadFeatureCheckboxIds() {
    try {
        return new Set(JSON.parse(localStorage.getItem(FEATURE_CHECKBOXES_KEY)) || []);
    } catch (e) {
        return new Set();
    }
}

function saveFeatureCheckboxIds() {
    storageSet(
        FEATURE_CHECKBOXES_KEY,
        JSON.stringify([...enabledFeatureCheckboxIds])
    );
}

function setLevelRange(min, max) {
    const lo = Math.max(1, Number(min) || 1);
    const hi = Math.max(lo, Math.min(settingInt('max_character_level', 65), Number(max) || lo));
    const current = Number(level.value);
    const next = Number.isFinite(current) ? Math.min(hi, Math.max(lo, current)) : lo;
    level.min = String(lo);
    level.max = String(hi);
    if (level.tagName === 'SELECT') {
        level.textContent = '';
        for (let i = lo; i <= hi; i++) {
            level.appendChild(new Option(String(i), String(i)));
        }
    }
    level.value = String(next);
}

function buildInfo(rows) {
    rows.forEach(r => {
        const parentId = r.parent_id == null ? null : Number(r.parent_id);

        INFO[r.name] = {
            id: Number(r.id),
            parent_id: parentId,
            type: parentId === null ? 'base' : 'adv',
            parent: parentId === null ? r.name : r.parent_name,
            min: Number(r.min_level),
            max: Number(r.max_level),
        };
    });
}

function buildSelect(rows) {
    classSelect.textContent = '';

    const gBase = document.createElement('optgroup');
    gBase.label = 'Классы';

    rows
        .filter(r => r.parent_id == null)
        .forEach(r => gBase.appendChild(new Option(r.name, r.name)));

    classSelect.appendChild(gBase);

    const groups = new Map();

    rows
        .filter(r => r.parent_id != null)
        .forEach(r => {
            if (!groups.has(r.parent_name)) {
                groups.set(r.parent_name, []);
            }

            groups.get(r.parent_name).push(r.name);
        });

    for (const [groupName, names] of groups) {
        const group = document.createElement('optgroup');
        group.label = groupName;

        names.forEach(name => group.appendChild(new Option(name, name)));
        classSelect.appendChild(group);
    }
}

function getCharacter() {
    const info = INFO[classSelect.value];

    return {
        gender: genderSelect.value,
        classId: info ? info.id : 0,
        level: parseInt(level.value, 10) || 1,
    };
}

function getEquipmentPayload() {
    return Object.fromEntries(
        Object.entries(equipment).map(([slot, item]) => [slot, Number(item.id)])
    );
}

async function loadSlotTypes() {
    const character = getCharacter();
    if (!character.classId) {
        availableTypesBySlot = new Map();
        return;
    }
    const signal = beginRequest('slot_types');
    try {
        const rows = await apiJson('slot_types', {
            gender: character.gender,
            class_id: String(character.classId),
            level: String(character.level),
        }, signal);
        const map = new Map();
        (Array.isArray(rows) ? rows : []).forEach(r => {
            const slot = Number(r.slot_number);
            const typeId = Number(r.item_type_id);
            if (!map.has(slot)) {
                map.set(slot, new Set());
            }
            map.get(slot).add(typeId);
        });
        availableTypesBySlot = map;
    } catch (e) {
        if (isAbortError(e)) {
            return;
        }
        reportLoadError('доступные типы ячеек', e);
        availableTypesBySlot = new Map();
    } finally {
        endRequest('slot_types', signal);
    }
}

function getEffectiveRules(slotNumber) {
    const info = INFO[classSelect.value];

    if (!info) {
        return new Map();
    }

    const slot = Number(slotNumber);
    const cacheKey = slot + '|' + info.id;
    const cached = EFFECTIVE_RULES_CACHE.get(cacheKey);

    if (cached) {
        return cached;
    }

    const rules = new Map();
    const byClass = SLOT_RULES_INDEX.get(slot);

    if (byClass) {
        // Сначала родительский класс, затем прямой — прямой перекрывает.
        if (info.parent_id) {
            const parentRules = byClass.get(Number(info.parent_id));

            if (parentRules) {
                parentRules.forEach((restriction, typeId) => rules.set(typeId, restriction));
            }
        }

        const directRules = byClass.get(Number(info.id));

        if (directRules) {
            directRules.forEach((restriction, typeId) => rules.set(typeId, restriction));
        }
    }

    EFFECTIVE_RULES_CACHE.set(cacheKey, rules);

    return rules;
}

function isSlotHidden(slotNumber) {
    const rules = getEffectiveRules(Number(slotNumber));
    return rules.get(0) === 'hide';
}

function applySlotVisibility() {
    document.querySelectorAll('.slot').forEach(slotEl => {
        const slotNumber = Number(slotEl.dataset.slot);
        slotEl.hidden = isSlotHidden(slotNumber);
    });
}

function getEquippedTypeTimestamps(excludeSlot = null) {
    const map = new Map();

    Object.entries(equipment).forEach(([slot, item]) => {
        if (excludeSlot !== null && Number(slot) === Number(excludeSlot)) {
            return;
        }

        const typeId = Number(item.type_id || 0);
        const equippedAt = Number(item.equipped_at || 0);

        if (!typeId) {
            return;
        }

        const prev = Number(map.get(typeId) || 0);

        if (equippedAt > prev) {
            map.set(typeId, equippedAt);
        }
    });

    return map;
}

function isTypeAvailableForSlot(slotNumber, typeId) {
    if (!availableTypesBySlot.size) {
        return true;
    }

    const set = availableTypesBySlot.get(Number(slotNumber));

    return Boolean(set && set.has(Number(typeId)));
}

function getSlotRuleContext(slotNumber) {
    const rules = getEffectiveRules(slotNumber);

    // Учитываем только предметы из других ячеек.
    const equippedTypes = getEquippedTypeTimestamps(slotNumber);

    let locked = false;
    let activeException = null;
    let activeExceptionAt = -1;

    // Явные правила "Доступно" — дополняют исключение.
    const explicitAllowed = new Set();

    for (const [typeId, restriction] of rules) {
        // Правила show/hide хранятся с typeId = 0.
        // Они не участвуют в фильтрации предметов внутри ячейки.
        if (typeId === 0) {
            continue;
        }

        if (restriction === 'allowed') {
            explicitAllowed.add(typeId);
            continue;
        }

        if (restriction === 'locked' && equippedTypes.has(typeId)) {
            locked = true;
        }

        if (
            restriction === 'exception' &&
            equippedTypes.has(typeId) &&
            isTypeAvailableForSlot(slotNumber, typeId)
        ) {
            const equippedAt = Number(equippedTypes.get(typeId) || 0);

            if (
                equippedAt > activeExceptionAt ||
                (equippedAt === activeExceptionAt && (activeException === null || typeId < activeException))
            ) {
                activeException = typeId;
                activeExceptionAt = equippedAt;
            }
        }
    }

    return {
        rules,
        locked,
        activeException,
        explicitAllowed,
    };
}

function shouldRemoveItemByRules(slotNumber, item, context) {
    if (!item) {
        return false;
    }

    if (context.locked) {
        return true;
    }

    const typeId = Number(item.type_id || 0);
    const restriction = context.rules.get(typeId) ?? 'allowed';

    if (restriction === 'denied' || restriction === 'locked') {
        return true;
    }

    if (
        context.activeException !== null &&
        typeId !== context.activeException &&
        !context.explicitAllowed.has(typeId)
    ) {
        return true;
    }

    return false;
}

let isApplyingRules = false;

function applyRuleValidation() {
    if (isApplyingRules) {
        return;
    }
    isApplyingRules = true;
    let dirty = false;
    try {
        let changed = false;
        let guard = 0;
        do {
            changed = false;
            for (const slot of getSlotNumbers()) {
                const slotKey = String(slot);
                const item = equipment[slotKey];
                if (isSlotHidden(slot)) {
                    if (item) {
                        delete equipment[slotKey];
                        changed = true;
                    }
                    continue;
                }
                if (!item) {
                    continue;
                }
                const context = getSlotRuleContext(slot);
                if (shouldRemoveItemByRules(slot, item, context)) {
                    delete equipment[slotKey];
                    changed = true;
                }
            }
            if (changed) {
                dirty = true;
            }
        } while (changed && ++guard < 32);
        if (changed) {
            console.warn('Правила ячеек не сошлись за 32 итерации — возможен цикл зависимостей.');
        }
    } finally {
        isApplyingRules = false;
    }
    if (dirty) {
        saveEquipment();
    }
    updateSlotMarkers();
    updateSlotLocks();
    applySlotVisibility();
}

function updateSlotLocks() {
    lockedSlots = new Set();
    for (const slot of getSlotNumbers()) {
        const context = getSlotRuleContext(slot);

        if (context.locked) {
            lockedSlots.add(String(slot));
        }
    }

    applySlotLocks();
}

function updateSlotTitle(slotEl) {
    const saved = equipment[slotEl.dataset.slot];
    if (!saved) {
        slotEl.title = getSlotName(slotEl.dataset.slot);
        return;
    }
    const parts = [
        getSlotName(slotEl.dataset.slot),
        saved.name,
    ];
    if (saved.grade) {
        parts.push('грейд ' + getGradeName(saved.grade));
    }
    if (Number(saved.required_level || 0) > 0) {
        parts.push('ур. ' + Number(saved.required_level));
    }

    if (Number(saved.can_upgrade || 0) === 1 && Number(saved.upgrade || 0) > 0) {
        parts.push('улучшение +' + Number(saved.upgrade || 0));
    }

    slotEl.title = parts.join(' · ');
}

function updateSlotMarkers() {
    document.querySelectorAll('.slot').forEach(slotEl => {
        const saved = equipment[slotEl.dataset.slot];
        const icon = slotEl.querySelector('.slot-icon');

        if (!icon) {
            return;
        }

        if (saved && saved.icon) {
            icon.src = saved.icon;
            icon.alt = saved.name;
            icon.hidden = false;
            slotEl.classList.add('has-item');
        } else {
            icon.removeAttribute('src');
            icon.hidden = true;
            slotEl.classList.remove('has-item');
        }

        updateSlotTitle(slotEl);
    });
}

function applySlotLocks() {
    document.querySelectorAll('.slot').forEach(slotEl => {
        const locked = lockedSlots.has(String(slotEl.dataset.slot));

        slotEl.classList.toggle('locked', locked);

        if (locked) {
            slotEl.title = 'Ячейка заблокирована';
        } else {
            updateSlotTitle(slotEl);
        }
    });
}

async function validateEquipment() {
    const character = getCharacter();
    if (!character.classId || !Object.keys(equipment).length) {
        return;
    }
    const signal = beginRequest('validate');
    try {
        const res = await fetchWithTimeout(API + '?action=validate_equipment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                gender: character.gender,
                class_id: character.classId,
                level: character.level,
                equipment: getEquipmentPayload(),
            }),
            signal,
        });
        const text = await res.text();
        let data = null;
        let parseFailed = false;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (e) {
            parseFailed = true;
        }
        if (!res.ok) {
            throw new Error(data && data.error ? data.error : 'HTTP ' + res.status);
        }
        if (parseFailed) {
            throw new Error('Некорректный JSON в ответе сервера.');
        }
        if (data && data.error) {
            throw new Error(data.error);
        }
        let changed = false;
        (data.invalid_slots || []).forEach(slot => {
            const key = String(slot);
            if (equipment[key]) {
                delete equipment[key];
                changed = true;
            }
        });
        if (changed) {
            saveEquipment();
            updateSlotMarkers();
        }
    } catch (e) {
        if (isAbortError(e)) {
            return;
        }
        console.error('Не удалось проверить экипировку:', e);
    } finally {
        endRequest('validate', signal);
    }
}

let characterChangeToken = 0;
async function onCharacterChange() {
    saveCharacterSelection();
    const token = ++characterChangeToken;
    await validateEquipment();
    await loadSlotTypes();
    if (token !== characterChangeToken) {
        return;
    }
    applyRuleValidation();
    if (modalOverlay.hidden) {
        return;
    }
    if (activeSlot !== null && (isSlotHidden(activeSlot) || lockedSlots.has(String(activeSlot)))) {
        closeModal();
        return;
    }
    await loadItems();
}

function onClassChange() {
    const newVal = classSelect.value;
    if (prevSelected === newVal) {
        return;
    }
    const newInfo = INFO[newVal];
    // Уровень сохраняем — только подгоняем под диапазон нового класса.
    setLevelRange(newInfo.min, newInfo.max);
    prevSelected = newVal;
    onCharacterChange();
}

function onLevelChange() {
    const min = parseInt(level.min, 10);
    const max = parseInt(level.max, 10);

    let v = parseInt(level.value, 10);

    if (isNaN(v)) {
        v = min;
    }

    v = Math.min(max, Math.max(min, v));
    level.value = v;

    onCharacterChange();
}

