function getStampState() {
    const saved = getSavedActiveItem();
    if (!saved) {
        return null;
    }
    const server = EQUIPMENT_SERVER_STAMPS.get(Number(saved.id || 0)) || null;
    return {
        locked: server ? Number(server.is_locked || 0) === 1 : false,
        variant: getActiveStampForEquippedItem(saved),
        hasUser: Object.prototype.hasOwnProperty.call(saved, 'user_stamp'),
        serverVariant: server ? server.variant : null,
    };
}

function getStampContext() {
    const saved = getSavedActiveItem();
    const hostItem = saved ? getCalcItemForSavedItem(saved) : null;
    return {
        itemTypeId: Number((hostItem && hostItem.item_type_id) || (saved && saved.type_id) || itemStampState.itemTypeId || 0),
        internalLevel: getStampInternalLevel(hostItem, saved),
        upgradeLevel: saved ? normalizeUpgradeForItem(hostItem || saved, Number(saved.upgrade ?? modalUpgrade ?? 0)) : 0,
        hostItemActive: hostItem ? isItemStateActive(hostItem) : false,
        hostItemStateId: hostItem ? (hostItem.state_id ?? null) : null,
    };
}

function getStampStats(variant, context, stateReason = '') {
    const stats = [];
    const resolveContext = {
        kind: 'stamp',
        tab: 'stamps',
        itemTypeId: Number(context.itemTypeId || 0),
        internalLevel: Number(context.internalLevel || 0),
        upgradeLevel: Number(context.upgradeLevel || 0),
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
    };
    (variant.bonuses || []).forEach(bonus => {
        if (Number(bonus.quality_value || 0) === 0) {
            return;
        }
        const resolved = resolveBonusContext(bonus, resolveContext);
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
    prependStateNotice(stats, stateReason);
    return stats;
}

function getStampSearchText(variant) {
    return memoizedSearchText(variant, 0, () => [
        variant.name || '',
        variant.color_name || '',
        ...(variant.bonuses || []).map(bonus => bonus.name_ru),
        ...(variant.bonuses || []).map(bonus => bonus.stat_alias),
    ].filter(Boolean).join(' ').toLowerCase());
}

function getStampSearchStats(variant) {
    const context = getStampContext();
    const resolveContext = {
        kind: 'stamp',
        tab: 'stamps',
        itemTypeId: context.itemTypeId,
        internalLevel: context.internalLevel,
        upgradeLevel: context.upgradeLevel,
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
    };
    const stats = [];
    (variant.bonuses || []).forEach(bonus => {
        if (Number(bonus.quality_value || 0) === 0) {
            return;
        }
        const resolved = resolveBonusContext(bonus, resolveContext);
        if (!resolved.active) {
            return;
        }
        const names = statSearchNames(bonus.name_ru, bonus.stat_alias);
        if (names.length) {
            stats.push({
                names,
                value: Number(resolved.value || 0),
                valueMax: null
            });
        }
    });
    return stats;
}
/* --- Группировка вариантов в печати (одна карточка на печать) --- */
function getStampGroupsForContext() {
    const groups = new Map();
    currentStampsAll.forEach(variant => {
        if (!isClassRestrictionActive(variant.class_ids)) {
            return;
        }
        const stampId = Number(variant.stamp_id || 0);
        if (!stampId) {
            return;
        }
        if (!groups.has(stampId)) {
            groups.set(stampId, {
                id: stampId,
                name: variant.name || '',
                sort_order: Number(variant.stamp_sort_order || 0),
                variants: [],
            });
        }
        groups.get(stampId).variants.push(variant);
    });
    const list = Array.from(groups.values());
    list.forEach(group => group.variants.sort((a, b) =>
        Number(a.sort_order || 0) - Number(b.sort_order || 0) ||
        Number(a.color_sort_order || 0) - Number(b.color_sort_order || 0) ||
        Number(a.id) - Number(b.id)));
    list.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    return list;
}

function getStampGroupColors(groups) {
    const colors = new Map();
    groups.forEach(group => (group.variants || []).forEach(variant => {
        const id = Number(variant.color_id || 0);
        if (id && !colors.has(id)) {
            colors.set(id, {
                id,
                name: variant.color_name || '',
                sort_order: Number(variant.color_sort_order || 0),
            });
        }
    }));
    return Array.from(colors.values()).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
}

function findStampVariantByColor(group, colorId) {
    const id = Number(colorId || 0);
    return (group.variants || []).find(variant => Number(variant.color_id) === id) || null;
}

function resolveStampGroupVariant(group, state) {
    if (state.variant && Number(state.variant.stamp_id) === group.id) {
        return getFullStampVariant(state.variant.id, state.variant) || group.variants[0];
    }
    return findStampVariantByColor(group, stampColorId) || group.variants[0];
}

function normalizeStampColorId(groups, state) {
    if (state.variant) {
        stampColorId = Number(state.variant.color_id || 0) || null;
        return;
    }
    const colors = getStampGroupColors(groups);
    if (!colors.length) {
        stampColorId = null;
        return;
    }
    if (!colors.some(color => color.id === Number(stampColorId))) {
        stampColorId = colors[0].id;
    }
}

function renderStampColorSelect(groups, state) {
    if (!stampColorSelect || !stampColorField) {
        return;
    }
    stampColorSelect.textContent = '';
    const saved = getSavedActiveItem();
    if (modalOverlay.hidden || modalTab !== 'stamps' || !saved) {
        stampColorField.hidden = true;
        return;
    }
    stampColorField.hidden = false;
    let colors;
    if (state.locked) {
        colors = state.variant ? getStampGroupColors([{
            variants: [state.variant]
        }]) : [];
    } else if (state.variant) {
        const group = groups.find(g => g.id === Number(state.variant.stamp_id || 0));
        colors = group ? getStampGroupColors([group]) : getStampGroupColors([{
            variants: [state.variant]
        }]);
    } else {
        colors = getStampGroupColors(groups);
    }
    if (!colors.length) {
        stampColorSelect.appendChild(new Option('—', ''));
        stampColorSelect.disabled = true;
        return;
    }
    colors.forEach(color => stampColorSelect.appendChild(new Option(color.name, String(color.id))));
    stampColorSelect.value = stampColorId ? String(stampColorId) : '';
    stampColorSelect.disabled = state.locked || colors.length < 2;
}

function filterStampGroupsBySearch(groups, state) {
    const parsed = parseSearchQuery(searchInput.value);
    if (!parsed.terms.length) {
        return groups;
    }
    return groups.filter(group => {
        const variant = resolveStampGroupVariant(group, state);
        return searchMatchesTerms(getStampSearchText(variant), () => getStampSearchStats(variant), parsed);
    });
}

function createStampElement(variant) {
    const template = document.getElementById('item-card-template');
    const cardEl = template.content.firstElementChild.cloneNode(true);
    cardEl.querySelector('.item-icon-wrap')?.remove();
    cardEl.querySelector('.item-icon')?.remove();
    cardEl.querySelector('.item-icon-fallback')?.remove();
    cardEl.querySelector('.item-name').textContent = variant.name;
    const typeEl = cardEl.querySelector('.item-type');
    typeEl.textContent = '';
    const colorWrap = document.createElement('span');
    colorWrap.className = 'stamp-variant-color';
    const dot = document.createElement('span');
    dot.className = 'stamp-color-dot';
    dot.style.background = variant.color_hex || '#8b909c';
    colorWrap.appendChild(dot);
    colorWrap.appendChild(document.createTextNode(variant.color_name || ''));
    typeEl.appendChild(colorWrap);
    cardEl.querySelector('.item-footer')?.remove();
    return cardEl;
}

function updateStampElement(cardEl, variant, options) {
    const context = options.context;
    let stateReason = '';
    if (!context.hostItemActive) {
        stateReason = context.hostItemStateId ?
            getStateInactiveReason(context.hostItemStateId) :
            'Не участвует в расчётах: предмет не активен';
    }
    renderStatsList(cardEl.querySelector('.item-stats'), getStampStats(variant, context, stateReason));
    cardEl.classList.toggle('selected', Boolean(options.selected));
    cardEl.classList.toggle('item-inactive', !context.hostItemActive);
}

const syncStampGrid = createCardGridCache(createStampElement, updateStampElement);
let stampsGridEl = null;

function getStampGridContextSignature(context) {
    return [
        currentStateId,
        getSelectedClassId(),
        Array.from(enabledFeatureCheckboxIds).sort().join(','),
        Array.from(getKnownEquippedCardIds()).sort((a, b) => a - b).join(','),
        mapSignature(CALC_STATE.totals),
        mapSignature(getEquippedItemTypeCounts()),
        context.itemTypeId,
        context.internalLevel,
        context.upgradeLevel,
        context.hostItemActive ? 1 : 0,
        context.hostItemStateId ?? '',
    ].join('|');
}

function appendStampGrid(container, variants, context, options = {}) {
    if (!variants.length) {
        return;
    }
    if (!stampsGridEl) {
        stampsGridEl = document.createElement('div');
        stampsGridEl.className = 'item-grid';
    }
    const selectedId = options.selectedId !== undefined && options.selectedId !== null ?
        Number(options.selectedId) :
        null;
    syncStampGrid(stampsGridEl, variants, getStampGridContextSignature(context), variant => ({
        context,
        selectable: options.selectable,
        onClick: options.onClick,
        selected: selectedId !== null && Number(variant.id) === selectedId,
    }));
    container.appendChild(stampsGridEl);
}

function renderStampPanel() {
    const saved = getSavedActiveItem();
    if (modalOverlay.hidden || modalTab !== 'stamps' || !saved) {
        modalSockets.hidden = true;
        modalSockets.textContent = '';
        if (stampColorField) {
            stampColorField.hidden = true;
        }
        return;
    }
    modalSockets.textContent = '';
    modalSockets.hidden = false;
    if (itemStampState.loading) {
        const loading = document.createElement('div');
        loading.className = 'socket-empty';
        loading.textContent = 'Загрузка печати...';
        modalSockets.appendChild(loading);
        return;
    }
    const state = getStampState();
    const chip = document.createElement('div');
    chip.className = 'socket-chip';
    if (state.locked) {
        chip.classList.add('forced');
        chip.title = 'Принудительная печать. Замена невозможна.';
    } else {
        chip.title = state.variant ? 'Печать предмета' : 'Печать не наложена';
    }
    const main = document.createElement('div');
    main.className = 'socket-chip-main';
    if (state.variant) {
        const dot = document.createElement('span');
        dot.className = 'stamp-color-dot';
        dot.style.background = state.variant.color_hex || '#8b909c';
        main.appendChild(dot);
    }
    const text = document.createElement('div');
    text.className = 'socket-text';
    const title = document.createElement('span');
    title.className = 'socket-title';
    title.textContent = 'Печать';
    const value = document.createElement('span');
    value.className = 'socket-card';
    value.textContent = state.variant ?
        state.variant.name + ' · ' + (state.variant.color_name || '') :
        'Не наложена';
    text.appendChild(title);
    text.appendChild(value);
    main.appendChild(text);
    chip.appendChild(main);
    if (!state.locked && state.variant) {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'socket-remove';
        remove.textContent = '×';
        remove.title = 'Снять печать';
        remove.addEventListener('click', event => {
            event.stopPropagation();
            removeStamp();
        });
        chip.appendChild(remove);
    }
    modalSockets.appendChild(chip);
}

function renderStampsTab() {
    modalCenter.innerHTML = '';
    const saved = getSavedActiveItem();
    if (!saved) {
        renderSockets();
        updateModalTabs();
        return;
    }
    if (itemStampState.loading) {
        modalCenter.textContent = 'Загрузка...';
        renderSockets();
        updateModalTabs();
        return;
    }
    const state = getStampState();
    const groups = getStampGroupsForContext();
    normalizeStampColorId(groups, state);
    renderStampColorSelect(groups, state);
    if (state.locked) {
        if (state.variant) {
            appendStampGrid(modalCenter, [state.variant], getStampContext(), {
                selectedId: Number(state.variant.id),
                selectable: false,
            });
        } else {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'Для предмета задана принудительная печать, но она недоступна.';
            modalCenter.appendChild(empty);
        }
        renderSockets();
        updateModalTabs();
        return;
    }
    const filtered = filterStampGroupsBySearch(groups, state);
    if (!filtered.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = groups.length ?
            'Нет печатей по запросу.' :
            'Нет печатей, доступных для этого предмета.';
        modalCenter.appendChild(empty);
        renderSockets();
        updateModalTabs();
        return;
    }
    appendStampGrid(
        modalCenter,
        filtered.map(group => resolveStampGroupVariant(group, state)),
        getStampContext(),
        {
            selectedId: state.variant ? Number(state.variant.id) : null,
            selectable: true,
            onClick: selectStampVariant,
        }
    );
    renderSockets();
    updateModalTabs();
    scrollSelectedIntoView();
}

function loadStampsForItemType(itemTypeId) {
    if (!itemTypeId) {
        currentStampsAll = [];
        stampsItemType = null;
        return;
    }
    if (stampsItemType === itemTypeId) {
        return;
    }
    currentStampsAll = Array.from(STAMP_VARIANTS.values())
        .filter(variant => Number(variant.stamp_id || 0) > 0 && isStampAllowedForItemType(variant, itemTypeId))
        .sort((a, b) =>
            Number(a.stamp_sort_order || 0) - Number(b.stamp_sort_order || 0) ||
            Number(a.stamp_id) - Number(b.stamp_id) ||
            Number(a.sort_order || 0) - Number(b.sort_order || 0) ||
            Number(a.color_sort_order || 0) - Number(b.color_sort_order || 0) ||
            Number(a.id) - Number(b.id));
    stampsItemType = itemTypeId;
}
async function loadItemStampForItem(itemId, force = false) {
    if (!itemId) {
        itemStampState = emptyItemStampState();
        return;
    }
    if (!force && itemStampState.loadedItemId === itemId) {
        return;
    }
    // Серверная печать меняется только через админку.
    // EQUIPMENT_SERVER_STAMPS переживает resetCardsState (шаг 1) —
    // если запись уже есть, собираем состояние из неё без запроса.
    const cached = !force ? EQUIPMENT_SERVER_STAMPS.get(Number(itemId)) : null;
    if (cached) {
        if (cached.variant) {
            markStampVariantFull(cached.variant);
        }
        const cachedItem = ITEM_CACHE.get(Number(itemId));
        itemStampState = {
            loadedItemId: Number(itemId),
            itemTypeId: Number((cachedItem && cachedItem.item_type_id) || 0),
            isLocked: Number(cached.is_locked || 0) === 1,
            variant: cached.variant || null,
            loading: false,
        };
        return;
    }
    const signal = beginRequest('item_stamp');
    try {
        const data = await apiJson('item_stamps', {
            item_id: String(itemId)
        }, signal);
        if (data.variant) {
            markStampVariantFull(data.variant);
        }
        EQUIPMENT_SERVER_STAMPS.set(Number(itemId), {
            is_locked: Number(data.is_locked || 0),
            variant: data.variant || null,
        });
        itemStampState = {
            loadedItemId: Number(itemId),
            itemTypeId: Number(data.item_type_id || 0),
            isLocked: Number(data.is_locked || 0) === 1,
            variant: data.variant || null,
            loading: false,
        };
        // Принудительная печать лишь перекрывает выбор пользователя,
        // но не удаляет его — при смене предмета выбор вернётся.
    } finally {
        endRequest('item_stamp', signal);
    }
}
let stampsViewToken = 0;
const stampsViewConfig = {
    tab: 'stamps',
    errorLabel: 'Ошибка загрузки печатей: ',
    bumpToken: () => ++stampsViewToken,
    getToken: () => stampsViewToken,
    getState: () => itemStampState,
    setState: state => { itemStampState = state; },
    renderTab: renderStampsTab,
    renderOtherTab: () => {
        renderSockets();
    },
    resetLists: () => {
        currentStampsAll = [];
        stampsItemType = null;
        itemStampState = emptyItemStampState();
    },
    load: async (itemId, itemTypeId, force) => {
        loadStampsForItemType(itemTypeId);
        await loadItemStampForItem(itemId, force);
    },
    afterLoad: () => {},
};

async function refreshStampsView(force = false) {
    return refreshEntityView(stampsViewConfig, force);
}

function selectStampVariant(variant) {
    const saved = getSavedActiveItem();
    if (!saved) {
        return;
    }
    const state = getStampState();
    if (state.locked) {
        return;
    }
    if (state.variant && Number(state.variant.id) === Number(variant.id)) {
        removeStamp();
        return;
    }
    markStampVariantFull(variant);
    saved.user_stamp = makeStampSnapshot(variant);
    stampColorId = Number(variant.color_id || 0) || null;
    saveEquipment();
    renderSockets();
    renderStampsTab();
}

function removeStamp() {
    const saved = getSavedActiveItem();
    if (!saved) {
        return;
    }
    const state = getStampState();
    if (state.locked || !state.variant) {
        return;
    }
    saved.user_stamp = null;
    saveEquipment();
    renderSockets();
    renderStampsTab();
}