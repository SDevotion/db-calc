function openModal(slotNumber) {
    activeSlot = Number(slotNumber);
    if (lockedSlots.has(String(activeSlot)) || isSlotHidden(activeSlot)) {
        return;
    }
    if (!getVisibleTabSlugs().length) {
        return;
    }

    currentItems = [];

    searchInput.value = '';
    searchStale = false;
    modalUpgrade = 0;
    modalTab = getDefaultModalTab();
    renderFeatureCheckboxes();

    resetCardsState();
    disableUpgradeControl();

    lastFocusedElement = document.activeElement;
    invalidateRender();
    modalOverlay.hidden = false;
    updateModalTabs();
    loadItems();

    requestAnimationFrame(() => {
        searchInput.focus();
    });
}

function closeModal() {
    abortRequest('items');
    abortRequest('cards');
    abortRequest('item_cards');
    abortRequest('item_stamp');
    itemsRequestToken++;
    cardsViewToken++;
    stampsViewToken++;
    invalidateRender();
    modalOverlay.hidden = true;
    activeSlot = null;
    modalUpgrade = 0;
    modalTab = getDefaultModalTab();
    renderFeatureCheckboxes();
    resetCardsState();
    disableUpgradeControl();
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
        lastFocusedElement.focus();
    }
    lastFocusedElement = null;
}

function initSlotIconErrors() {
    document.querySelectorAll('.slot-icon').forEach(icon => {
        icon.addEventListener('error', () => {
            const slotEl = icon.closest('.slot');

            if (!slotEl) {
                return;
            }

            icon.hidden = true;
            slotEl.classList.remove('has-item');
        });
    });
}

async function saveItem(item) {
    if (activeSlot === null) {
        return;
    }

    const slotKey = String(activeSlot);
    const saved = equipment[slotKey];

    if (saved && Number(saved.id) === Number(item.id)) {
        delete equipment[slotKey];
        modalUpgrade = 0;

        saveEquipment();
        applyRuleValidation();

        resetCardsState();
        ensureValidModalTab();
        updateModalTabs();
        renderModalContent();
        return;
    }

    const upgrade = normalizeUpgradeForItem(item, modalUpgrade);

    equipment[slotKey] = {
        id: Number(item.id),
        name: item.name,
        icon: item.icon_path,
        type: item.type_name,
        type_id: Number(item.item_type_id),
        grade: item.grade,
        required_level: Number(item.required_level || 0),
        internal_level: Number(item.internal_level || 0),
        sockets: Number(item.sockets || 0),
        can_upgrade: Number(item.can_upgrade || 0),
        upgrade: upgrade,
        equipped_at: Date.now(),
        user_cards: {},
    };

    ITEM_CACHE.set(Number(item.id), item);
    // Переносим карты и печать, выбранные для предыдущего предмета в этой ячейке.
    if (saved) {
        if (saved.user_cards && typeof saved.user_cards === 'object') {
            equipment[slotKey].user_cards = {
                ...saved.user_cards
            };
        }
        if (Object.prototype.hasOwnProperty.call(saved, 'user_stamp')) {
            equipment[slotKey].user_stamp = saved.user_stamp;
        }
    }
    saveEquipment();
    applyRuleValidation();
    resetCardsState();

    if (lockedSlots.has(slotKey)) {
        closeModal();
        return;
    }

    ensureValidModalTab();
    updateModalTabs();

    if (modalTab === 'cards') {
        await refreshCardsView();
    } else if (modalTab === 'stamps') {
        await refreshStampsView();
    } else {
        renderModalContent();
    }
}

// Список предметов зависит только от slot|gender|class|level.
// Кэш живёт до перезагрузки страницы — как и bootstrap-справочники.
const ITEMS_LIST_CACHE = new Map();
const ITEMS_LIST_CACHE_LIMIT = 50;

function rememberItemsList(key, items) {
    if (ITEMS_LIST_CACHE.size >= ITEMS_LIST_CACHE_LIMIT) {
        const oldest = ITEMS_LIST_CACHE.keys().next().value;
        ITEMS_LIST_CACHE.delete(oldest);
    }
    ITEMS_LIST_CACHE.set(key, items);
}

let itemsRequestToken = 0;
async function loadItems() {
    if (activeSlot === null) {
        return;
    }
    if (lockedSlots.has(String(activeSlot)) || isSlotHidden(activeSlot)) {
        closeModal();
        return;
    }
    const token = ++itemsRequestToken;
    const slot = Number(activeSlot);
    currentItems = [];
    resetCardsState();
    const character = getCharacter();
    if (!character.classId) {
        modalCenter.textContent = 'Класс не выбран.';
        disableUpgradeControl();
        updateModalTabs();
        return;
    }
    const cacheKey = [slot, character.gender, character.classId, character.level].join('|');
    const cachedItems = ITEMS_LIST_CACHE.get(cacheKey);
    if (cachedItems) {
        const saved = equipment[String(slot)];
        modalUpgrade = saved ? Number(saved.upgrade || 0) : 0;
        invalidateRender();
        renderItems(cachedItems);
        if (modalTab === 'cards') {
            await refreshCardsView();
        } else if (modalTab === 'stamps') {
            await refreshStampsView();
        }
        return;
    }
    modalCenter.textContent = 'Загрузка...';
    disableUpgradeControl();
    updateModalTabs();
    const signal = beginRequest('items');
    try {
        const data = await apiJson('items', {
            slot: String(slot),
            gender: character.gender,
            class_id: String(character.classId),
            level: String(character.level),
        }, signal);
        if (token !== itemsRequestToken || modalOverlay.hidden || activeSlot !== slot) {
            return;
        }
        const items = Array.isArray(data) ? data : [];
        items.forEach(item => {
            const id = Number(item && item.id ? item.id : 0);
            if (id) {
                ITEM_CACHE.set(id, item);
            }
        });
        rememberItemsList(cacheKey, items);
        const saved = equipment[String(slot)];
        modalUpgrade = saved ? Number(saved.upgrade || 0) : 0;
        invalidateRender();
        renderItems(items);
        if (modalTab === 'cards') {
            await refreshCardsView();
        } else if (modalTab === 'stamps') {
            await refreshStampsView();
        }
    } catch (e) {
        if (isAbortError(e) || token !== itemsRequestToken) {
            return;
        }
        currentItems = [];
        resetCardsState();
        modalUpgrade = 0;
        modalCenter.textContent = 'Ошибка загрузки предметов: ' + e.message;
        disableUpgradeControl();
        updateModalTabs();
    } finally {
        endRequest('items', signal);
    }
}

function getVisibleItems(items) {
    if (activeSlot === null) {
        return [];
    }

    const context = getSlotRuleContext(activeSlot);

    if (context.locked) {
        return [];
    }

    return items.filter(item => {
        const typeId = Number(item.item_type_id || 0);
        const restriction = context.rules.get(typeId) ?? 'allowed';

        if (restriction === 'denied' || restriction === 'locked') {
            return false;
        }

        if (
            context.activeException !== null &&
            typeId !== context.activeException &&
            !context.explicitAllowed.has(typeId)
        ) {
            return false;
        }

        return true;
    });
}

function getItemSearchUpgradeLevel(item) {
    const saved = activeSlot !== null ? equipment[String(activeSlot)] : null;

    if (saved && Number(saved.id) === Number(item.id)) {
        return normalizeUpgradeForItem(item, Number(saved.upgrade || 0));
    }

    return 0;
}

function getItemSearchText(item) {
    const upgradeLevel = getItemSearchUpgradeLevel(item);
    return memoizedSearchText(item, upgradeLevel, () => {
        const baseNames = [];
        BASE_STATS.forEach(def => {
            if (!getBaseStatDisplay(item, def, upgradeLevel)) {
                return;
            }
            baseNames.push(def.name_ru);
            if (def.alias) {
                baseNames.push(def.alias);
            }
        });
        return [
            item.name || '',
            ...baseNames,
            ...getBonusSearchableStatNames(item),
        ].join(' ').toLowerCase();
    });
}

function statSearchNames(nameRu, alias) {
    return [nameRu, alias].filter(Boolean).map(name => String(name).toLowerCase());
}

function getItemSearchStats(item) {
    const upgradeLevel = getItemSearchUpgradeLevel(item);
    const stats = [];
    BASE_STATS.forEach(def => {
        const display = getBaseStatDisplay(item, def, upgradeLevel);
        if (!display || display.numeric === undefined || display.numeric === null) {
            return;
        }
        const names = statSearchNames(def.name_ru, def.alias);
        if (!names.length) {
            return;
        }
        stats.push({
            names,
            value: Number(display.numeric),
            valueMax: null
        });
    });
    const context = {
        kind: 'item',
        tab: 'items',
        itemTypeId: Number(item.item_type_id || 0),
        upgradeLevel,
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
    };

    (item.bonuses || []).forEach(bonus => {
        const baseValue = Number(bonus.value || 0);

        if (baseValue === 0 && bonus.scaling_type !== 'range') {
            return;
        }

        const resolved = resolveBonusContext(bonus, context);

        if (!resolved.active || resolved.neutralized) {
            return;
        }

        const names = statSearchNames(bonus.name_ru, bonus.stat_alias);

        if (!names.length) {
            return;
        }

        stats.push({
            names,
            value: Number(resolved.value || 0),
            valueMax: resolved.valueMax,
        });
    });

    return stats;
}

function filterBySearch(entries, getText, getStats) {
    const parsed = parseSearchQuery(searchInput.value);

    if (!parsed.terms.length) {
        return entries;
    }

    // getStats передаём лениво: вызовется только для элементов, прошедших
    // текстовый фильтр, и только если в запросе есть условия (>=, >, =, ...).
    return entries.filter(entry => searchMatchesTerms(getText(entry), () => getStats(entry), parsed));
}

function getBonusSearchableStatNames(entity) {
    const names = [];
    (entity.bonuses || []).forEach(bonus => {
        const baseValue = Number(bonus.value || 0);
        const hasValue = bonus.scaling_type === 'range' ?
            (baseValue !== 0 || Number(bonus.value_max || 0) !== 0) :
            baseValue !== 0;
        if (!hasValue) {
            return;
        }
        names.push(bonus.name_ru);
        const alias = String(bonus.stat_alias || '').trim();
        if (alias !== '') {
            names.push(alias);
        }
    });
    return names;
}

function renderItemsInner(items) {
    currentItems = Array.isArray(items) ? items : [];

    const slotKey = activeSlot !== null ? String(activeSlot) : null;
    let saved = slotKey ? equipment[slotKey] : null;

    if (saved && !currentItems.some(item => Number(item.id) === Number(saved.id))) {
        delete equipment[slotKey];
        modalUpgrade = 0;
        saveEquipment();
        updateSlotMarkers();
        saved = null;
    }

    const ruleVisibleItems = getVisibleItems(currentItems);

    if (saved && !ruleVisibleItems.some(item => Number(item.id) === Number(saved.id))) {
        delete equipment[slotKey];
        modalUpgrade = 0;
        saveEquipment();
        updateSlotMarkers();
        saved = null;
    }

    ensureValidModalTab();

    const visibleItems = filterBySearch(ruleVisibleItems, getItemSearchText, getItemSearchStats);

    modalCenter.innerHTML = '';

    if (!currentItems.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Нет доступных предметов.';
        modalCenter.appendChild(empty);
        updateUpgradeControlForSelected();
        updateModalTabs();
        scrollSelectedIntoView();
        return;
    }

    if (!ruleVisibleItems.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Нет доступных предметов для этой ячейки.';
        modalCenter.appendChild(empty);
        updateUpgradeControlForSelected();
        updateModalTabs();
        scrollSelectedIntoView();
        return;
    }

    if (!visibleItems.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Нет предметов по запросу.';
        modalCenter.appendChild(empty);
        updateUpgradeControlForSelected();
        updateModalTabs();
        scrollSelectedIntoView();
        return;
    }

    const grid = document.createElement('div');
    grid.className = 'item-grid';

    visibleItems.forEach(item => {
        grid.appendChild(createItemCard(item));
    });

    modalCenter.appendChild(grid);
    updateUpgradeControlForSelected();
    updateModalTabs();
    scrollSelectedIntoView();
}

function renderStatsList(statsList, stats, allowDescription = false) {
    if (!stats.length) {
        statsList.hidden = true;
        return;
    }

    statsList.textContent = '';

    stats.forEach(stat => {
        const li = document.createElement('li');

        if (stat.isStateNotice) {
            li.className = 'item-state-notice';
            li.textContent = stat.value;
            statsList.appendChild(li);
            return;
        }

        const valueSpan = document.createElement('span');
        valueSpan.className = 'item-stat-value';
        valueSpan.textContent = stat.value;

        if (allowDescription && stat.isDescription) {
            li.classList.add('item-description');
        } else {
            li.textContent = stat.label + ': ';
        }

        li.appendChild(valueSpan);

        renderStatNote(li, stat.note);
        renderStatNote(li, stat.reasons);

        if (stat.isHidden) {
            li.classList.add('item-hidden-stat');
        }

        statsList.appendChild(li);
    });
}

function createItemCard(item) {
    const template = document.getElementById('item-card-template');
    const card = template.content.firstElementChild.cloneNode(true);

    const icon = card.querySelector('.item-icon');
    const fallback = card.querySelector('.item-icon-fallback');
    const name = card.querySelector('.item-name');
    const type = card.querySelector('.item-type');
    const statsList = card.querySelector('.item-stats');

    icon.addEventListener('error', () => {
        icon.hidden = true;
        fallback.hidden = false;
    });

    icon.src = item.icon_path;
    icon.alt = item.name;

    name.textContent = item.name;
    type.textContent = item.type_name;

    const saved = activeSlot !== null ? equipment[String(activeSlot)] : null;
    const isSelected = saved && Number(saved.id) === Number(item.id);
    const upgradeLevel = isSelected ? normalizeUpgradeForItem(item, saved.upgrade || 0) : 0;

    const stats = getItemStats(item, upgradeLevel);

    renderStatsList(statsList, stats, true);

    const levelWrap = card.querySelector('.item-required-level');
    const gradeWrap = card.querySelector('.item-grade');
    if (levelWrap) {
        levelWrap.hidden = !(Number(item.required_level || 0) > 0);
        levelWrap.querySelector('.item-footer-value').textContent = item.required_level ?? '';
    }
    if (gradeWrap) {
        gradeWrap.hidden = !item.grade;
        gradeWrap.querySelector('.item-footer-value').textContent = item.grade ? getGradeName(item.grade) : '';
    }

    if (isSelected) {
        card.classList.add('selected');
    }

    if (!isItemStateActive(item)) {
        card.classList.add('item-inactive');
    }

    card.addEventListener('click', () => saveItem(item));

    return card;
}

const SLOTS = new Map();
const SLOT_COLUMNS = [];

function getSlotNumbers() {
    return Array.from(SLOTS.keys()).sort((a, b) => a - b);
}

function getSlotName(slotNumber) {
    const slot = SLOTS.get(Number(slotNumber || 0));
    return slot && slot.name ? slot.name : 'Ячейка ' + Number(slotNumber || 0);
}
async function loadSlots() {
    try {
        const data = fromBootstrap('slots') ?? await apiJson('slots');
        SLOTS.clear();
        SLOT_COLUMNS.length = 0;
        (Array.isArray(data && data.columns) ? data.columns : []).forEach(row => {
            const no = Number(row.column_no || 0);
            if (!no) {
                return;
            }
            SLOT_COLUMNS.push({
                column_no: no,
                name: String(row.name || ''),
                align: String(row.align || 'left'),
                sort_order: Number(row.sort_order || 0),
            });
        });
        (Array.isArray(data && data.slots) ? data.slots : []).forEach(row => {
            const no = Number(row.slot_number || 0);
            if (!no) {
                return;
            }
            const rawPair = row.pair_group;
            SLOTS.set(no, {
                slot_number: no,
                name: String(row.name || ('Ячейка ' + no)),
                column_no: Number(row.column_no || 0),
                pair_group: rawPair === null || rawPair === undefined || rawPair === '' ?
                    null :
                    Number(rawPair),
                sort_order: Number(row.sort_order || 0),
            });
        });
        if (!SLOTS.size) {
            reportLoadError('ячейки', new Error('Таблица slots пустая.'));
        }
    } catch (e) {
        reportLoadError('ячейки', e);
    }
}

function createSlotElement(slot) {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'slot';
    el.dataset.slot = String(slot.slot_number);
    el.title = slot.name;
    el.setAttribute('aria-label', slot.name);
    const number = document.createElement('span');
    number.className = 'slot-number';
    number.textContent = String(slot.slot_number);
    const icon = document.createElement('img');
    icon.className = 'slot-icon';
    icon.hidden = true;
    icon.alt = '';
    el.appendChild(number);
    el.appendChild(icon);
    el.addEventListener('click', () => {
        if (el.classList.contains('locked') || el.hidden) {
            return;
        }
        openModal(slot.slot_number);
    });
    return el;
}

function renderSlots() {
    const container = document.querySelector('.slots');
    if (!container) {
        return;
    }
    container.textContent = '';
    const byColumn = new Map();
    Array.from(SLOTS.values())
        .sort((a, b) => a.sort_order - b.sort_order || a.slot_number - b.slot_number)
        .forEach(slot => {
            if (!byColumn.has(slot.column_no)) {
                byColumn.set(slot.column_no, []);
            }
            byColumn.get(slot.column_no).push(slot);
        });
    SLOT_COLUMNS
        .slice()
        .sort((a, b) => a.sort_order - b.sort_order || a.column_no - b.column_no)
        .forEach(column => {
            const list = byColumn.get(column.column_no) || [];
            if (!list.length) {
                return;
            }
            const col = document.createElement('div');
            col.className = 'slot-col' + (column.align === 'right' ? ' right-col' : '');
            let index = 0;
            while (index < list.length) {
                const group = list[index].pair_group;
                if (group === null) {
                    col.appendChild(createSlotElement(list[index]));
                    index++;
                    continue;
                }
                const pair = list.slice(index).filter(item => item.pair_group === group);
                const wrap = document.createElement('div');
                wrap.className = 'pair';
                pair.forEach(item => wrap.appendChild(createSlotElement(item)));
                col.appendChild(wrap);
                index += pair.length;
            }
            container.appendChild(col);
        });
}
const BASE_STATS = [];

function applyBaseStats(rows) {
    BASE_STATS.length = 0;
    (Array.isArray(rows) ? rows : []).forEach(row => {
        const field = String(row.base_field || '').trim();
        const format = String(row.base_format || '').trim();
        if (!field || !format) {
            return;
        }
        BASE_STATS.push({
            id: Number(row.id || 0),
            name_ru: String(row.name_ru || field),
            alias: row.alias === null || row.alias === undefined ? '' : String(row.alias),
            base_field: field,
            base_format: format,
            sort_order: Number(row.sort_order || 0),
        });
    });
}
async function loadBaseStats() {
    try {
        applyBaseStats(fromBootstrap('base_stats') ?? await apiJson('base_stats'));
        if (!BASE_STATS.length) {
            reportLoadError('базовые характеристики', new Error('В stats нет записей с is_base = 1.'));
        }
    } catch (e) {
        reportLoadError('базовые характеристики', e);
    }
}

function getBaseStatDisplay(item, def, upgradeLevel) {
    const format = String(def.base_format || 'plain');
    if (format === 'text') {
        const text = String(item[def.base_field] ?? '').trim();
        if (text === '') {
            return null;
        }
        const featureId = Number(item.description_feature_id || 0);
        if (isFeatureVisible(featureId)) {
            return {
                value: text,
                isDescription: true
            };
        }
        return {
            value: '?',
            note: reasonFeature(getFeatureDisplayName(featureId))
        };
    }
    const raw = Number(item[def.base_field] ?? 0);
    if (format === 'dps') {
        const baseAttack = Number(item.base_attack || 0);
        const attackPercent = getUpgradePercent(upgradeLevel, 'attack_percent');
        let dps = raw;
        if (attackPercent !== 0 && raw !== 0 && baseAttack > 0) {
            const speed = truncateTo3(1 / (raw / baseAttack));
            const upgradedAttack = baseAttack * (1 + attackPercent / 100);
            dps = speed > 0 ? roundUpgradeValue(upgradedAttack / speed) : raw;
        }
        return dps !== 0 ? {
            value: formatNumber(dps),
            numeric: dps
        } : null;
    }
    if (raw === 0) {
        return null;
    }
    if (format === 'upgrade_attack' || format === 'upgrade_defense') {
        const percentField = format === 'upgrade_attack' ? 'attack_percent' : 'defense_percent';
        const add = roundUpgradeValue(raw * getUpgradePercent(upgradeLevel, percentField) / 100);
        return {
            value: formatBaseWithUpgrade(raw, add),
            numeric: raw + add
        };
    }
    return {
        value: formatNumber(raw),
        numeric: raw
    };
}

function getItemStats(item, upgradeLevel = null) {
    const stats = [];
    const resolvedUpgradeLevel = upgradeLevel === null || upgradeLevel === undefined ?
        getUpgradeLevelForItem(item) :
        Number(upgradeLevel || 0);
    BASE_STATS.forEach(def => {
        const display = getBaseStatDisplay(item, def, resolvedUpgradeLevel);
        if (!display) {
            return;
        }
        stats.push({
            label: def.name_ru,
            value: display.value,
            isDescription: Boolean(display.isDescription),
            note: display.note || '',
        });
    });
    const context = {
        kind: 'item',
        itemTypeId: Number(item.item_type_id || 0),
        upgradeLevel: resolvedUpgradeLevel,
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
    };

    (item.bonuses || []).forEach(bonus => {
        const baseValue = Number(bonus.value || 0);

        if (baseValue === 0 && bonus.scaling_type !== 'range') {
            return;
        }

        const resolved = resolveBonusContext(bonus, context);

        if (resolved.active) {
            stats.push({
                label: bonus.name_ru,
                value: formatResolvedBonusValue(resolved, bonus),
                note: bonus.text_note_text || '',
                reasons: Array.isArray(resolved.hints) ? resolved.hints : [],
            });
        } else {
            stats.push({
                label: bonus.name_ru,
                value: '?',
                isHidden: true,
                note: '',
                reasons: resolved.reasons,
            });
        }
    });

    if (!isItemStateActive(item)) {
        prependStateNotice(stats, getStateInactiveReason(item.state_id));
    }

    return stats;
}

