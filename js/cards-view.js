function resetCardsState() {
    currentCardsAll = [];
    cardsItemType = null;
    activeSocketNumber = null;
    itemCardsState = emptyItemCardsState();
    currentStampsAll = [];
    stampsItemType = null;
    itemStampState = emptyItemStampState();
    stampColorId = null;
    renderSockets();
}

function getCurrentCardContextItemTypeId() {
    const saved = getSavedActiveItem();

    return Number(saved && saved.type_id ? saved.type_id : itemCardsState.itemTypeId || 0);
}

function getCurrentCardUpgradeLevel() {
    const saved = getSavedActiveItem();

    if (!saved) {
        return 0;
    }

    return normalizeUpgradeForItem(saved, Number(saved.upgrade ?? modalUpgrade ?? 0));
}

function findCardBonusOverride(bonus, itemTypeId) {
    const overrides = Array.isArray(bonus.overrides) ? bonus.overrides : [];

    if (!itemTypeId || !overrides.length) {
        return null;
    }

    const typeId = Number(itemTypeId);

    return overrides.find(override => Number(override.item_type_id) === typeId) || null;
}

function getCardSearchText(card) {
    return [
        card.name || '',
        card.card_type_name || '',
        card.set && card.set.name ? card.set.name : '',
        ...getBonusSearchableStatNames(card),
    ].join(' ').toLowerCase();
}

function getCardSearchStats(card) {
    const itemTypeId = getCurrentCardContextItemTypeId();
    const upgradeLevel = getCurrentCardUpgradeLevel();

    const setId = getCardSetId(card);
    const isMain = isCardMainSetCard(card);
    const set = setId ? CARD_SETS.get(setId) : null;

    const isOverrideSet = Boolean(
        set &&
        (!Array.isArray(set.bonuses) || set.bonuses.length === 0)
    );

    const setNegativeMode = !isMain && isOverrideSet ?
        (typeof getSetNegativeMode === 'function' ? getSetNegativeMode(setId) : 'allow') :
        null;

    const context = {
        kind: 'card',
        tab: 'cards',
        cardId: Number(card && card.id ? card.id : 0),
        itemTypeId: Number(itemTypeId || 0),
        upgradeLevel: Number(upgradeLevel || 0),
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
        setNegativeMode,
    };

    const stats = [];

    (card.bonuses || []).forEach(bonus => {
        const baseValue = Number(bonus.value || 0);
        const isRange = bonus.scaling_type === 'range';

        const hasBaseValue = isRange ?
            (baseValue !== 0 || Number(bonus.value_max || 0) !== 0) :
            baseValue !== 0;

        if (!hasBaseValue) {
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

function getCardSetId(card) {
    return Number(
        card && card.set_id ?
        card.set_id :
        (card && card.set && card.set.id ? card.set.id : 0)
    );
}

function isCardMainSetCard(card) {
    return Number(card && card.is_set_main ? card.is_set_main : 0) === 1;
}

function isCardAllowedForItemGrade(card, grade) {
    const allowed = Array.isArray(card && card.allowed_grades) ?
        card.allowed_grades : [];

    if (!allowed.length) {
        return true;
    }

    const itemGrade = String(grade || '').trim().toUpperCase();
    if (!itemGrade) {
        return false;
    }

    return allowed
        .map(value => String(value || '').trim().toUpperCase())
        .includes(itemGrade);
}

function getSetBonusStats(card) {
    const stats = [];

    const setId = getCardSetId(card);

    if (!setId || !isCardMainSetCard(card)) {
        return stats;
    }

    const set = CARD_SETS.get(setId);

    if (!set || !Array.isArray(set.bonuses) || !set.bonuses.length) {
        return stats;
    }

    const state = typeof getSetState === 'function' ?
        getSetState(setId) :
        null;

    const hasMain = Boolean(state && state.has_main);
    const ordinaryCount = Math.max(0, Number(state && state.ordinary_count || 0));

    set.bonuses.forEach(bonus => {
        const required = Math.max(0, Number(bonus.required_count || 0));
        const active = hasMain && ordinaryCount >= required;

        if (active) {
            const value = Number(bonus.value || 0);

            stats.push({
                label: bonus.name_ru,
                value: value === 0 ? '0' : formatBonus(value, bonus.value_type),
            });
        } else {
            stats.push({
                label: bonus.name_ru,
                value: '?',
                isHidden: true,
                note: [
                    reasonSetCount(required)
                ],
            });
        }
    });

    return stats;
}

function getCardStats(card, itemTypeId = 0, upgradeLevel = 0, stateReason = '') {
    const stats = [];

    const setId = getCardSetId(card);
    const isMain = isCardMainSetCard(card);
    const set = setId ? CARD_SETS.get(setId) : null;

    const isOverrideSet = Boolean(
        set &&
        (!Array.isArray(set.bonuses) || set.bonuses.length === 0)
    );

    const setNegativeMode = !isMain && isOverrideSet ?
        (typeof getSetNegativeMode === 'function' ? getSetNegativeMode(setId) : 'allow') :
        null;

    const context = {
        kind: 'card',
        tab: 'cards',
        cardId: Number(card && card.id ? card.id : 0),
        itemTypeId: Number(itemTypeId || 0),
        upgradeLevel: Number(upgradeLevel || 0),
        equippedItemTypeCounts: getEquippedItemTypeCounts(),
        setNegativeMode,
    };

    (card.bonuses || []).forEach(bonus => {
        const baseValue = Number(bonus.value || 0);
        const isRange = bonus.scaling_type === 'range';

        const hasBaseValue = isRange ?
            (baseValue !== 0 || Number(bonus.value_max || 0) !== 0) :
            baseValue !== 0;

        if (!hasBaseValue) {
            return;
        }

        const resolved = resolveBonusContext(bonus, context);

        if (resolved.neutralized) {
            return;
        }

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

    getSetBonusStats(card).forEach(stat => stats.push(stat));

    prependStateNotice(stats, stateReason);

    return stats;
}

function createCardCard(card, options = {}) {
    const template = document.getElementById('item-card-template');
    const cardEl = template.content.firstElementChild.cloneNode(true);

    const icon = cardEl.querySelector('.item-icon');
    const fallback = cardEl.querySelector('.item-icon-fallback');
    const name = cardEl.querySelector('.item-name');
    const type = cardEl.querySelector('.item-type');
    const statsList = cardEl.querySelector('.item-stats');
    const footer = cardEl.querySelector('.item-footer');

    icon.addEventListener('error', () => {
        icon.hidden = true;
        fallback.hidden = false;
    });

    icon.src = card.icon_path;
    icon.alt = card.name;

    name.textContent = card.name;

    let typeText = card.card_type_name || '';

    if (card.card_type_socket_number !== null && card.card_type_socket_number !== undefined) {
        //typeText += ' · Слот ' + card.card_type_socket_number;
    }

    if (options.lockedBadge) {
        //typeText += ' · принудительная';
    }

    type.textContent = typeText;

    footer?.remove();

    const hostItemActive = options.hostItemActive !== false;
    const hostItemStateId = options.hostItemStateId ?? null;

    let stateReason = '';

    if (!hostItemActive) {
        stateReason = hostItemStateId ?
            getStateInactiveReason(hostItemStateId) :
            'Не участвует в расчётах: предмет не активен';
    } else if (!isCardStateActive(card)) {
        stateReason = getStateInactiveReason(card.state_id);
    }

    const stats = getCardStats(
        card,
        Number(options.itemTypeId || 0),
        Number(options.upgradeLevel || 0),
        stateReason
    );

    renderStatsList(statsList, stats);

    if (options.selected) {
        cardEl.classList.add('selected');
    }

    if (!hostItemActive || !isCardStateActive(card)) {
        cardEl.classList.add('item-inactive');
    }

    if (options.selectable && typeof options.onClick === 'function') {
        cardEl.disabled = false;
        cardEl.addEventListener('click', () => options.onClick(card));
    } else {
        cardEl.disabled = true;
    }

    return cardEl;
}

function appendCardGrid(container, cards, options = {}) {
    if (!cards.length) {
        return;
    }

    const grid = document.createElement('div');
    grid.className = 'item-grid';

    const selectedId = options.selectedId !== undefined && options.selectedId !== null ?
        Number(options.selectedId) :
        null;

    cards.forEach(card => {
        const cardOptions = {
            ...options
        };
        delete cardOptions.selectedId;

        if (selectedId !== null && Number(card.id) === selectedId) {
            cardOptions.selected = true;
        }

        grid.appendChild(createCardCard(card, cardOptions));
    });

    container.appendChild(grid);
}

function renderCardsTabInner() {
    modalCenter.innerHTML = '';

    if (typeof refreshSetStatesSync === 'function') {
        refreshSetStatesSync();
    }

    const saved = getSavedActiveItem();

    if (!saved) {
        renderSockets();
        updateModalTabs();
        return;
    }

    if (itemCardsState.loading) {
        modalCenter.textContent = 'Загрузка...';
        renderSockets();
        updateModalTabs();
        return;
    }

    const states = getSocketStates();

    if (!states.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'У этого предмета нет слотов.';
        modalCenter.appendChild(empty);
        renderSockets();
        updateModalTabs();
        return;
    }

    const activeState = states.find(state => state.socket_number === activeSocketNumber) || null;

    const contextItemTypeId = getCurrentCardContextItemTypeId();
    const contextUpgradeLevel = getCurrentCardUpgradeLevel();
    const hostItem = getCalcItemForSavedItem(saved);
    const hostItemActive = hostItem ? isItemStateActive(hostItem) : false;
    const hostItemStateId = hostItem ? (hostItem.state_id ?? null) : null;

    if (activeState && activeState.locked) {
        if (activeState.card) {
            appendCardGrid(modalCenter, [activeState.card], {
                selectable: false,
                lockedBadge: true,
                selectedId: Number(activeState.card.id),
                itemTypeId: contextItemTypeId,
                upgradeLevel: contextUpgradeLevel,
                hostItemActive,
                hostItemStateId,
            });

            scrollSelectedIntoView();
        } else {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'В этом слоте установлена принудительная карта.';
            modalCenter.appendChild(empty);
        }

        renderSockets();
        updateModalTabs();
        return;
    }

    const activeCardId = activeState && activeState.card ?
        Number(activeState.card.id) :
        null;

    const contextItemGrade = String(
        (hostItem && hostItem.grade ? hostItem.grade : (saved && saved.grade ? saved.grade : '')) || ''
    ).toUpperCase();

    const availableCards = activeSocketNumber !== null ?
        currentCardsAll.filter(card =>
            isCardCompatibleWithSocket(card, activeSocketNumber) &&
            isCardAllowedForItemGrade(card, contextItemGrade)
        ) : [];

    const filteredAvailable = filterBySearch(availableCards, getCardSearchText, getCardSearchStats);

    if (!filteredAvailable.length) {
        const empty = document.createElement('div');
        empty.className = 'empty';

        if (activeSocketNumber !== null) {
            empty.textContent = 'Нет карт, доступных для слота ' + activeSocketNumber + '.';
        } else {
            empty.textContent = 'Нет доступных карт.';
        }

        modalCenter.appendChild(empty);
        renderSockets();
        updateModalTabs();
        return;
    }

    appendCardGrid(modalCenter, filteredAvailable, {
        selectable: true,
        onClick: selectCardForActiveSocket,
        selectedId: activeCardId,
        itemTypeId: contextItemTypeId,
        upgradeLevel: contextUpgradeLevel,
        hostItemActive,
        hostItemStateId,
    });

    renderSockets();
    updateModalTabs();
    scrollSelectedIntoView();
}

async function loadCardsForItemType(itemTypeId, force = false) {
    if (!itemTypeId) {
        currentCardsAll = [];
        cardsItemType = null;
        return;
    }
    if (!force && cardsItemType === itemTypeId) {
        return;
    }
    const signal = beginRequest('cards');
    try {
        const data = await apiJson('cards', {
            item_type_id: String(itemTypeId)
        }, signal);
        if (!Array.isArray(data)) {
            throw new Error('Некорректный ответ карт.');
        }
        currentCardsAll = data;
        currentCardsAll.forEach(markCardFull);
        cardsItemType = itemTypeId;
    } finally {
        endRequest('cards', signal);
    }
}

function cleanupUserCardsForCurrentItem() {
    const saved = getSavedActiveItem();
    if (!saved || !saved.user_cards || typeof saved.user_cards !== 'object') {
        return;
    }
    let changed = false;
    Object.keys(saved.user_cards).forEach(key => {
        const socket = Number(key);
        // Чистим только битые ключи. Карты в слотах, которых нет у текущего предмета,
        // и в принудительных слотах сохраняем — они вернутся при смене предмета.
        if (!Number.isFinite(socket) || socket < 1) {
            delete saved.user_cards[key];
            changed = true;
        }
    });
    if (changed) {
        saveEquipment();
    }
}

async function loadItemCardsForItem(itemId, force = false) {
    if (!itemId) {
        itemCardsState = emptyItemCardsState();
        return;
    }
    if (!force && itemCardsState.loadedItemId === itemId) {
        return;
    }
    const signal = beginRequest('item_cards');
    try {
        const data = await apiJson('item_cards', {
            item_id: String(itemId)
        }, signal);
        const sockets = Array.isArray(data.sockets) ? data.sockets : [];
        sockets.forEach(socket => {
            if (socket.card) {
                markCardFull(socket.card);
            }
        });
        itemCardsState = {
            loadedItemId: itemId,
            sockets,
            socketsCount: sockets.length,
            itemTypeId: Number(data.item_type_id || 0),
            loading: false,
        };
        cleanupUserCardsForCurrentItem();
    } finally {
        endRequest('item_cards', signal);
    }
}

let cardsViewToken = 0;
async function refreshEntityView(cfg, force = false) {
    ensureValidModalTab();
    updateModalTabs();
    if (modalTab !== cfg.tab) {
        cfg.renderOtherTab();
        return;
    }
    const token = cfg.bumpToken();
    const saved = getSavedActiveItem();
    if (!saved) {
        resetCardsState();
        cfg.renderTab();
        return;
    }
    const itemId = Number(saved.id);
    const itemTypeId = Number(saved.type_id || cfg.getState().itemTypeId || 0);
    if (!itemId || !itemTypeId) {
        cfg.resetLists();
        renderSockets();
        cfg.renderTab();
        return;
    }
    const slot = activeSlot;
    try {
        cfg.setState({
            ...cfg.getState(),
            loading: true
        });
        renderSockets();
        modalCenter.textContent = 'Загрузка...';
        await cfg.load(itemId, itemTypeId, force);
        if (token !== cfg.getToken()) {
            return;
        }
        if (modalOverlay.hidden || activeSlot !== slot || modalTab !== cfg.tab) {
            cfg.setState({
                ...cfg.getState(),
                loading: false
            });
            invalidateRender();
            return;
        }
        cfg.setState({
            ...cfg.getState(),
            loading: false
        });
        cfg.afterLoad();
        renderSockets();
        cfg.renderTab();
    } catch (e) {
        if (isAbortError(e) || token !== cfg.getToken()) {
            return;
        }
        cfg.setState({
            ...cfg.getState(),
            loading: false
        });
        if (modalOverlay.hidden || activeSlot !== slot || modalTab !== cfg.tab) {
            invalidateRender();
            return;
        }
        modalCenter.textContent = cfg.errorLabel + e.message;
        markRendered(cfg.tab);
        renderSockets();
        updateModalTabs();
    }
}

const cardsViewConfig = {
    tab: 'cards',
    errorLabel: 'Ошибка загрузки карт: ',
    bumpToken: () => ++cardsViewToken,
    getToken: () => cardsViewToken,
    getState: () => itemCardsState,
    setState: state => { itemCardsState = state; },
    renderTab: renderCardsTab,
    renderOtherTab: () => {
        renderSockets();
        renderModalContent();
    },
    resetLists: () => {
        currentCardsAll = [];
        cardsItemType = null;
        activeSocketNumber = null;
        itemCardsState = emptyItemCardsState();
    },
    load: async (itemId, itemTypeId, force) => {
        await loadCardsForItemType(itemTypeId, force);
        await loadItemCardsForItem(itemId, force);
    },
    afterLoad: () => ensureActiveSocket(),
};

async function refreshCardsView(force = false) {
    return refreshEntityView(cardsViewConfig, force);
}

