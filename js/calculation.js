const CALC_STATE = {
    totals: new Map(),
    resolved: new Map(),
    sets: new Map(),
    ready: false,
    updatedAt: 0,
};

window.CALC_STATE = CALC_STATE;

let equipmentCalcPromise = null;
let equipmentCalcTimer = null;
let equipmentCalcQueued = false;

function getEquippedItemIds() {
    return uniquePositiveIds(
        Object.values(equipment || {}).map(item => Number(item && item.id ? item.id : 0))
    );
}

function forEachActiveEquippedCard(callback) {
    Object.values(equipment || {}).forEach(savedItem => {
        if (!isSavedItemActiveForCalc(savedItem)) {
            return;
        }

        const itemId = Number(savedItem && savedItem.id ? savedItem.id : 0);

        if (!itemId) {
            return;
        }

        const item = ITEM_CACHE.get(itemId);
        const socketsCount = Number(
            item && item.sockets ?
            item.sockets :
            (savedItem.sockets || 0)
        );

        for (let socket = 1; socket <= socketsCount; socket++) {
            const card = getActiveCardForEquippedItem(savedItem, socket);

            if (card && isCardStateActive(card)) {
                callback(card, savedItem, socket, item);
            }
        }
    });
}

function collectActiveEquippedCardIds() {
    return collectIds(add => {
        forEachActiveEquippedCard(card => add(equippedEntityId(card)));
    });
}

function collectIds(each) {
    const ids = new Set();

    each(id => {
        if (id) {
            ids.add(id);
        }
    });

    return Array.from(ids);
}

function savedValueId(value) {
    return value && typeof value === 'object' ? Number(value.id || 0) : Number(value || 0);
}

function equippedEntityId(entity) {
    return Number(entity && entity.id ? entity.id : 0);
}

function collectEquippedUserCardIds() {
    return collectIds(add => {
        Object.values(equipment || {}).forEach(savedItem => {
            const userCards = savedItem && savedItem.user_cards && typeof savedItem.user_cards === 'object' ?
                savedItem.user_cards : {};

            Object.values(userCards).forEach(value => add(savedValueId(value)));
        });
    });
}

function migrateEquipmentUserCardSnapshots() {
    let changed = false;

    Object.values(equipment || {}).forEach(savedItem => {
        const userCards = savedItem && savedItem.user_cards && typeof savedItem.user_cards === 'object' ?
            savedItem.user_cards :
            null;

        if (!userCards) {
            return;
        }

        Object.keys(userCards).forEach(key => {
            const value = userCards[key];

            if (!value) {
                return;
            }

            const id = value && typeof value === 'object' ?
                Number(value.id || 0) :
                Number(value || 0);

            if (!id) {
                return;
            }

            const full = CARD_CACHE.get(id);

            if (!full) {
                return;
            }

            const snapshot = makeCardSnapshot(full);

            const needsUpdate = !value ||
                typeof value !== 'object' ||
                value.id !== snapshot.id ||
                value.name !== snapshot.name ||
                value.icon_path !== snapshot.icon_path ||
                value.card_type_name !== snapshot.card_type_name ||
                value.card_type_socket_number !== snapshot.card_type_socket_number ||
                value.set_id !== snapshot.set_id ||
                value.is_set_main !== snapshot.is_set_main;

            if (needsUpdate) {
                userCards[key] = snapshot;
                changed = true;
            }
        });
    });

    if (changed) {
        storageSet(EQUIPMENT_STORAGE_KEY, JSON.stringify(equipment));
    }
}
/* Удаляем из выбора то, чего больше нет на сервере. Карты в слотах,
   недоступных у текущего предмета, не трогаем — они не удалены, а просто не активны. */
function pruneDeletedUserSelections() {
    let changed = false;
    Object.values(equipment || {}).forEach(savedItem => {
        if (!savedItem) {
            return;
        }
        const stampValue = savedItem.user_stamp;
        const stampId = stampValue && typeof stampValue === 'object' ?
            Number(stampValue.id || 0) :
            Number(stampValue || 0);
        if (stampId) {
            const variant = STAMP_VARIANTS.get(stampId);
            if (!variant || !Array.isArray(variant.bonuses)) {
                delete savedItem.user_stamp;
                changed = true;
            }
        }
        const userCards = savedItem.user_cards && typeof savedItem.user_cards === 'object' ?
            savedItem.user_cards :
            null;
        if (!userCards) {
            return;
        }
        Object.keys(userCards).forEach(key => {
            const value = userCards[key];
            const cardId = value && typeof value === 'object' ?
                Number(value.id || 0) :
                Number(value || 0);
            if (cardId && !FULL_CARD_IDS.has(cardId)) {
                delete userCards[key];
                changed = true;
            }
        });
    });
    if (changed) {
        persistEquipment();
        stampsItemType = null;
        cardsItemType = null;
        CARDS_BY_TYPE_CACHE.clear();
        const typeId = Number(getSavedActiveItem()?.type_id || 0);
        if (typeId) {
            loadStampsForItemType(typeId);
        }
        invalidateRender();
    }
}

function getKnownCardIdsForCalc() {
    return new Set(collectActiveEquippedCardIds());
}

function getEquippedIdenticalCardCount(cardId) {
    const id = Number(cardId || 0);

    if (!id) {
        return 0;
    }

    let count = 0;

    forEachActiveEquippedCard(card => {
        if (Number(card && card.id ? card.id : 0) === id) {
            count++;
        }
    });

    return count;
}

function computeSetStates() {
    const states = new Map();

    CARD_SETS.forEach((set, id) => {
        states.set(id, {
            set_id: id,
            set_name: set.name,
            main_count: 0,
            ordinary_count: 0,
            has_main: false,
            total_count: 0,
        });
    });

    forEachActiveEquippedCard(card => {
        const setId = Number(
            card && card.set_id ?
            card.set_id :
            (card && card.set && card.set.id ? card.set.id : 0)
        );

        if (!setId) {
            return;
        }

        const state = states.get(setId);

        if (!state) {
            return;
        }

        const isMain = Number(card && card.is_set_main ? card.is_set_main : 0) === 1;

        if (isMain) {
            state.main_count++;
        } else {
            state.ordinary_count++;
        }
    });

    states.forEach(state => {
        state.total_count = state.main_count + state.ordinary_count;
        state.has_main = state.main_count > 0;
    });

    return states;
}

function refreshSetStatesSync() {
    const states = computeSetStates();
    CALC_STATE.sets = states;

    return states;
}

function getSetState(setId) {
    const id = Number(setId || 0);

    if (!id) {
        return null;
    }

    if (CALC_STATE.sets && CALC_STATE.sets.has(id)) {
        return CALC_STATE.sets.get(id);
    }

    const states = refreshSetStatesSync();

    return states.get(id) || null;
}

window.getSetState = getSetState;

function collectCandidateBonuses(setStates) {
    const candidates = [];

    const equippedItemTypeCounts = getEquippedItemTypeCounts();
    const knownCardIds = getKnownCardIdsForCalc();

    Object.values(equipment || {}).forEach(savedItem => {
        if (!isSavedItemActiveForCalc(savedItem)) {
            return;
        }

        const itemId = Number(savedItem && savedItem.id ? savedItem.id : 0);

        if (!itemId) {
            return;
        }

        const item = ITEM_CACHE.get(itemId);

        if (!item) {
            return;
        }

        const itemTypeId = Number(item.item_type_id || savedItem.type_id || 0);
        const upgradeLevel = Number(savedItem.upgrade || 0);

        (item.bonuses || []).forEach(bonus => {
            candidates.push({
                key: 'item:' + Number(bonus.id || 0),
                stat_id: Number(bonus.stat_id || 0),
                value_type: String(bonus.value_type || 'flat'),
                bonus,
                context: {
                    kind: 'item',
                    tab: 'items',
                    itemTypeId,
                    upgradeLevel,
                    equippedItemTypeCounts,
                    knownCardIds,
                },
            });
        });

        const stampVariant = getActiveStampForEquippedItem(savedItem);
        if (stampVariant) {
            const internalLevel = getStampInternalLevel(item, savedItem);
            (stampVariant.bonuses || []).forEach(bonus => {
                candidates.push({
                    key: 'stamp:' + itemId + ':' + Number(bonus.id || 0),
                    stat_id: Number(bonus.stat_id || 0),
                    value_type: String(bonus.value_type || 'flat'),
                    bonus,
                    context: {
                        kind: 'stamp',
                        tab: 'stamps',
                        itemTypeId,
                        upgradeLevel,
                        internalLevel,
                        hostItemId: itemId,
                        stampVariantId: Number(stampVariant.id || 0),
                        equippedItemTypeCounts,
                        knownCardIds,
                    },
                });
            });
        }
        const socketsCount = Number(item.sockets || savedItem.sockets || 0);
        for (let socket = 1; socket <= socketsCount; socket++) {
            const card = getActiveCardForEquippedItem(savedItem, socket);

            if (!card || !isCardStateActive(card)) {
                continue;
            }

            const cardSetId = Number(
                card && card.set_id ?
                card.set_id :
                (card && card.set && card.set.id ? card.set.id : 0)
            );

            const cardIsMain = Number(card && card.is_set_main ? card.is_set_main : 0) === 1;
            const set = cardSetId ? CARD_SETS.get(cardSetId) : null;

            const isOverrideSet = Boolean(
                set &&
                (!Array.isArray(set.bonuses) || set.bonuses.length === 0)
            );

            const setNegativeMode = !cardIsMain && isOverrideSet ?
                getSetNegativeMode(cardSetId, setStates) :
                null;

            (card.bonuses || []).forEach(bonus => {
                candidates.push({
                    key: 'card:' + itemId + ':' + socket + ':' + Number(bonus.id || 0),
                    stat_id: Number(bonus.stat_id || 0),
                    value_type: String(bonus.value_type || 'flat'),
                    bonus,
                    context: {
                        kind: 'card',
                        tab: 'cards',
                        itemTypeId,
                        upgradeLevel,
                        cardId: Number(card.id || 0),
                        socket,
                        hostItemId: itemId,
                        equippedItemTypeCounts,
                        knownCardIds,
                        setNegativeMode,
                    },
                });
            });
        }
    });

    setStates.forEach((state, setId) => {
        if (state.total_count <= 0) {
            return;
        }

        const set = CARD_SETS.get(setId);

        if (!set || !Array.isArray(set.bonuses) || !set.bonuses.length) {
            return;
        }

        set.bonuses.forEach(bonus => {
            candidates.push({
                key: 'set:' + setId + ':' + Number(bonus.id || 0),
                stat_id: Number(bonus.stat_id || 0),
                value_type: String(bonus.value_type || 'flat'),
                bonus: {
                    id: Number(bonus.id || 0),
                    stat_id: Number(bonus.stat_id || 0),
                    name_ru: bonus.name_ru,
                    slug: bonus.slug,
                    value: Number(bonus.value || 0),
                    value_max: null,
                    value_type: String(bonus.value_type || 'flat'),
                    feature_id: null,
                    text_note_id: null,
                    text_note_text: '',
                    state_id: null,
                    depends_on_card_id: null,
                    scaling_type: 'none',
                    scaling_step: 1,
                    scaling_threshold: 0,
                    item_type_condition_mode: 'all',
                    class_ids: [],
                    item_type_restrictions: [],
                    item_type_conditions: [],
                    overrides: [],
                    stat_source_id: null,
                    stat_step: null,
                    stat_threshold: null,
                    stat_value_true: null,
                },
                context: {
                    kind: 'set',
                    tab: 'cards',
                    setRequiredCount: Number(bonus.required_count || 0),
                    setHasMain: Boolean(state.has_main),
                    setOrdinaryCount: Number(state.ordinary_count || 0),
                },
            });
        });
    });

    return candidates;
}

function mapSignature(map) {
    return Array.from(map.entries())
        .sort((a, b) => Number(a[0]) - Number(b[0]))
        .map(([key, value]) => Number(key) + ':' + Number(value).toFixed(4))
        .join('|');
}

function calculateEquipmentStatTotals() {
    const setStates = refreshSetStatesSync();
    const candidates = collectCandidateBonuses(setStates);

    let totals = new Map();
    let resolved = new Map();
    let previousSignature = '';

    const maxIterations = 20;

    for (let iteration = 0; iteration < maxIterations; iteration++) {
        const nextTotals = new Map();
        const nextResolved = new Map();

        candidates.forEach(candidate => {
            const result = resolveBonusForCalculation(candidate.bonus, candidate.context, totals);
            nextResolved.set(candidate.key, result);

            if (result.active && candidate.value_type === 'flat') {
                const statId = Number(candidate.stat_id || 0);
                const value = Number(result.value || 0);

                if (statId && Number.isFinite(value)) {
                    nextTotals.set(statId, (nextTotals.get(statId) || 0) + value);
                }
            }
        });

        CALC_STATE.totals = nextTotals;
        CALC_STATE.resolved = nextResolved;

        const signature = mapSignature(nextTotals);

        if (signature === previousSignature) {
            break;
        }

        previousSignature = signature;
        totals = nextTotals;
        if (iteration === maxIterations - 1) {
            console.warn('Пересчёт характеристик не сошёлся за ' + maxIterations + ' итераций — возможен цикл зависимостей.');
        }
    }
    CALC_STATE.ready = true;
    CALC_STATE.updatedAt = Date.now();

    return CALC_STATE;
}

async function refreshEquipmentCalculationData(force = false) {
    if (equipmentCalcPromise) {
        equipmentCalcQueued = true;
        return equipmentCalcPromise;
    }

    equipmentCalcPromise = (async () => {
        if (force) {
            EQUIPMENT_SERVER_CARDS.clear();
            EQUIPMENT_SERVER_STAMPS.clear();
            FULL_CARD_IDS.clear();
        }

        const itemIds = getEquippedItemIds();

        if (!itemIds.length) {
            EQUIPMENT_SERVER_CARDS.clear();
            EQUIPMENT_SERVER_STAMPS.clear();
            CALC_STATE.totals = new Map();
            CALC_STATE.resolved = new Map();
            CALC_STATE.sets = refreshSetStatesSync();
            CALC_STATE.ready = true;
            CALC_STATE.updatedAt = Date.now();

            return CALC_STATE;
        }

        // Волна 1: предметы, серверные карты и серверные печати независимы
        await Promise.all([
            ensureItemsLoaded(itemIds),
            loadEquipmentServerCards(itemIds),
            loadEquipmentServerStamps(itemIds),
        ]);

        const cardIds = uniquePositiveIds([
            ...collectActiveEquippedCardIds(),
            ...collectEquippedUserCardIds(),
        ]);
        const stampIds = uniquePositiveIds([
            ...collectActiveEquippedStampIds(),
            ...collectEquippedUserStampIds(),
        ]);

        // Волна 2: добираем недостающие карты и варианты печатей
        await Promise.all([
            ensureCardsLoaded(cardIds),
            ensureStampsLoaded(stampIds),
        ]);

        migrateEquipmentUserCardSnapshots();
        pruneDeletedUserSelections();
        calculateEquipmentStatTotals();

        if (!modalOverlay.hidden && typeof renderModalContent === 'function') {
            renderModalContent();
        }

        return CALC_STATE;
    })();

    try {
        const state = await equipmentCalcPromise;
        equipmentCalcPromise = null;
        if (equipmentCalcQueued) {
            equipmentCalcQueued = false;
            return refreshEquipmentCalculationData(force);
        }
        return state;
    } finally {
        equipmentCalcPromise = null;
    }
}

function scheduleEquipmentCalculationRefresh(delay = 150) {
    if (equipmentCalcTimer) {
        clearTimeout(equipmentCalcTimer);
    }

    equipmentCalcTimer = setTimeout(() => {
        refreshEquipmentCalculationData().catch(error => {
            console.error('Ошибка пересчёта экипировки:', error);
        });
    }, delay);
}

window.refreshEquipmentCalculationData = refreshEquipmentCalculationData;
window.scheduleEquipmentCalculationRefresh = scheduleEquipmentCalculationRefresh;
window.refreshSetStatesSync = refreshSetStatesSync;
window.getEquippedIdenticalCardCount = getEquippedIdenticalCardCount;

window.getEquipmentStatTotal = function(statId) {
    return Number(CALC_STATE.totals.get(Number(statId || 0)) || 0);
};

window.getAllEquipmentStatTotals = function() {
    return Array.from(CALC_STATE.totals.entries())
        .map(([statId, value]) => ({
            statId: Number(statId),
            value: Number(value)
        }))
        .sort((a, b) => a.statId - b.statId);
};

window.getAllSetStates = function() {
    return Array.from(CALC_STATE.sets.values());
};

