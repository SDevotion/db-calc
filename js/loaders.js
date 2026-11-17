const CARD_CACHE = new Map();
const ITEM_CACHE = new Map();
const EQUIPMENT_SERVER_CARDS = new Map();
const FULL_CARD_IDS = new Set();

function getFullCard(id, fallback = null) {
    const num = Number(id || 0);
    const known = num ? CARD_CACHE.get(num) : null;
    if (known) {
        return known;
    }
    if (fallback) {
        return {
            ...fallback
        };
    }
    return null;
}

function makeCardSnapshot(card) {
    const setId = Number(
        card && card.set_id ?
        card.set_id :
        (card && card.set && card.set.id ? card.set.id : 0)
    );

    return {
        id: Number(card.id || 0),
        name: card.name || '',
        icon_path: card.icon_path || '',
        card_type_name: card.card_type_name || '',
        card_type_socket_number: card.card_type_socket_number ?? null,
        set_id: setId > 0 ? setId : null,
        is_set_main: Number(card && card.is_set_main ? card.is_set_main : 0),
    };
}

function mergeIntoCacheById(cache, entity) {
    if (!entity) {
        return 0;
    }

    const id = Number(entity.id || 0);

    if (!id) {
        return 0;
    }

    const prev = cache.get(id) || {};
    cache.set(id, {
        ...prev,
        ...entity
    });

    return id;
}

function markCardFull(card) {
    const id = mergeIntoCacheById(CARD_CACHE, card);

    if (id && Array.isArray(card.bonuses)) {
        FULL_CARD_IDS.add(id);
    }
}

function getServerSocketForItem(itemId, socketNumber) {
    const sockets = EQUIPMENT_SERVER_CARDS.get(Number(itemId || 0)) || [];

    return sockets.find(socket => Number(socket.socket_number) === Number(socketNumber)) || null;
}

function getActiveCardForEquippedItem(savedItem, socketNumber) {
    if (!savedItem) {
        return null;
    }

    const itemId = Number(savedItem.id || 0);
    const socket = Number(socketNumber || 0);

    if (!itemId || socket < 1) {
        return null;
    }

    const server = getServerSocketForItem(itemId, socket);
    const locked = server ? Number(server.is_locked || 0) === 1 : false;

    const userCards = savedItem.user_cards && typeof savedItem.user_cards === 'object' ?
        savedItem.user_cards : {};

    const key = String(socket);
    const hasUser = Object.prototype.hasOwnProperty.call(userCards, key);
    const userValue = userCards[key];

    if (locked) {
        return server && server.card ?
            getFullCard(server.card.id, server.card) :
            null;
    }

    if (hasUser) {
        if (!userValue) {
            return null;
        }

        if (typeof userValue === 'object') {
            return getFullCard(userValue.id, userValue);
        }

        return getFullCard(userValue, null);
    }

    return server && server.card ?
        getFullCard(server.card.id, server.card) :
        null;
}

async function apiJson(action, params = {}, signal = undefined) {
    const query = new URLSearchParams({
        action,
        ...params
    });
    const res = await fetchWithTimeout(API + '?' + query.toString(), {
        signal
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
    return data;
}

async function loadReferenceNames() {
    try {
        const data = fromBootstrap('reference_names') ?? await apiJson('reference_names');

        REFERENCE_NAMES.itemTypes.clear();
        REFERENCE_NAMES.cards.clear();
        REFERENCE_NAMES.stats.clear();
        REFERENCE_NAMES.classes.clear();
        REFERENCE_NAMES.classParents.clear();

        (data.item_types || []).forEach(row => {
            REFERENCE_NAMES.itemTypes.set(Number(row.id), String(row.name));
        });

        (data.card_names || []).forEach(row => {
            REFERENCE_NAMES.cards.set(Number(row.id), String(row.name));
        });

        (data.stat_names || []).forEach(row => {
            REFERENCE_NAMES.stats.set(Number(row.id), String(row.name));
        });

        (data.class_names || []).forEach(row => {
            const id = Number(row.id);
            const parentId = row.parent_id === null || row.parent_id === undefined ?
                null :
                Number(row.parent_id);

            REFERENCE_NAMES.classes.set(id, String(row.name));
            REFERENCE_NAMES.classParents.set(id, parentId);
        });
    } catch (e) {
        reportLoadError('справочники названий', e);
    }
}

async function loadCardSets() {
    try {
        const rows = fromBootstrap('card_sets') ?? await apiJson('card_sets');

        CARD_SETS.clear();

        (Array.isArray(rows) ? rows : []).forEach(set => {
            const id = Number(set.id || 0);

            if (!id) {
                return;
            }

            CARD_SETS.set(id, {
                id,
                name: String(set.name || ''),
                negative_threshold: Number(set.negative_threshold || 0),
                negative_below_mode: String(set.negative_below_mode || 'allow'),
                negative_reached_mode: String(set.negative_reached_mode || 'allow'),
                bonuses: Array.isArray(set.bonuses) ?
                    set.bonuses.map(bonus => ({
                        id: Number(bonus.id || 0),
                        set_id: Number(bonus.set_id || id),
                        required_count: Number(bonus.required_count || 0),
                        stat_id: Number(bonus.stat_id || 0),
                        name_ru: String(bonus.name_ru || ''),
                        slug: String(bonus.slug || ''),
                        value: Number(bonus.value || 0),
                        value_type: String(bonus.value_type || 'flat'),
                        sort_order: Number(bonus.sort_order || 0),
                    })) : [],
            });
        });
    } catch (e) {
        reportLoadError('сеты', e);
    }
}

async function loadStates() {
    try {
        const rows = fromBootstrap('states') ?? await apiJson('states');

        STATES.clear();

        (Array.isArray(rows) ? rows : []).forEach(row => {
            const id = Number(row.id || 0);

            if (!id) {
                return;
            }

            STATES.set(id, {
                id,
                slug: String(row.slug || ''),
                name: String(row.name || ''),
                is_default: Number(row.is_default || 0) === 1,
                sort_order: Number(row.sort_order || 0),
            });
        });

        renderStateSelect();
    } catch (e) {
        reportLoadError('состояния', e);
    }
}

function rebuildSlotRulesIndex() {
    SLOT_RULES_INDEX.clear();
    EFFECTIVE_RULES_CACHE.clear();

    // Обход в порядке массива: при дублях (slot, class, item_type)
    // побеждает последнее правило — та же семантика, что и раньше.
    SLOT_RULES.forEach(rule => {
        let byClass = SLOT_RULES_INDEX.get(rule.slot_number);

        if (!byClass) {
            byClass = new Map();
            SLOT_RULES_INDEX.set(rule.slot_number, byClass);
        }

        let byType = byClass.get(rule.class_id);

        if (!byType) {
            byType = new Map();
            byClass.set(rule.class_id, byType);
        }

        byType.set(rule.item_type_id, rule.restriction);
    });
}

async function loadSlotRules() {
    try {
        const rows = fromBootstrap('slot_rules') ?? await apiJson('slot_rules');

        SLOT_RULES.length = 0;

        rows.forEach(r => {
            SLOT_RULES.push({
                class_id: Number(r.class_id),
                item_type_id: r.item_type_id === null ? 0 : Number(r.item_type_id),
                slot_number: Number(r.slot_number),
                restriction: String(r.restriction),
            });
        });

        rebuildSlotRulesIndex();
    } catch (e) {
        reportLoadError('правила ячеек', e);
    }
}

async function loadUpgrades() {
    try {
        const rows = fromBootstrap('upgrades') ?? await apiJson('upgrades');

        if (!Array.isArray(rows)) {
            throw new Error(rows.error || 'Некорректный ответ upgrades.');
        }

        UPGRADES.clear();

        rows.forEach(r => {
            const upgradeLevel = Number(r.level);

            if (!Number.isFinite(upgradeLevel) || upgradeLevel < 0) {
                return;
            }

            UPGRADES.set(upgradeLevel, {
                attack_percent: Number(r.attack_percent || 0),
                defense_percent: Number(r.defense_percent || 0),
            });
        });

        if (!UPGRADES.size) {
            console.warn('Таблица upgrade_levels пустая.');
        }
    } catch (e) {
        reportLoadError('улучшения', e);
    }
}

async function loadItemTypeTabs() {
    try {
        const data = fromBootstrap('item_type_tabs') ?? await apiJson('item_type_tabs');
        TYPE_HIDDEN_TABS.clear();
        Object.entries(data || {}).forEach(([typeId, slugs]) => {
            TYPE_HIDDEN_TABS.set(Number(typeId), new Set((Array.isArray(slugs) ? slugs : []).map(String)));
        });
    } catch (e) {
        reportLoadError('видимость вкладок', e);
    }
}

function isTabVisibleForType(slug, typeId) {
    const hidden = TYPE_HIDDEN_TABS.get(Number(typeId || 0));
    return !(hidden && hidden.has(String(slug)));
}

function getVisibleTabSlugs() {
    const saved = getSavedActiveItem();
    const slugs = [];
    TABS.forEach(tab => {
        let visible;
        if (saved && Number(saved.type_id || 0) > 0) {
            visible = isTabVisibleForType(tab.slug, saved.type_id);
        } else {
            let types = activeSlot !== null ?
                (availableTypesBySlot.get(Number(activeSlot)) || new Set()) :
                new Set();
            if (!types.size && Array.isArray(currentItems)) {
                types = new Set(currentItems
                    .map(item => Number(item.item_type_id || 0))
                    .filter(id => id > 0));
            }
            visible = types.size ?
                Array.from(types).some(typeId => isTabVisibleForType(tab.slug, typeId)) :
                true;
        }
        if (visible) {
            slugs.push(tab.slug);
        }
    });
    return slugs;
}
async function loadGrades() {
    try {
        const rows = fromBootstrap('grades') ?? await apiJson('grades');
        GRADES.clear();
        (Array.isArray(rows) ? rows : []).forEach(row => {
            const code = String(row.code || '').trim().toUpperCase();
            if (!code) {
                return;
            }
            const rawMax = row.max_upgrade_level;
            GRADES.set(code, {
                code,
                name: String(row.name || code),
                sort_order: Number(row.sort_order || 0),
                max_upgrade_level: rawMax === null || rawMax === undefined || rawMax === '' ?
                    null :
                    Number(rawMax),
            });
        });
        if (!GRADES.size) {
            reportLoadError('грейды', new Error('Таблица grades пустая.'));
        }
    } catch (e) {
        reportLoadError('грейды', e);
    }
}

async function ensureLoaded(ids, action, isMissing, ingest) {
    const missing = uniquePositiveIds(ids).filter(isMissing);

    if (!missing.length) {
        return;
    }

    const data = await apiJson(action, {
        ids: missing.join(','),
    });

    (Array.isArray(data) ? data : []).forEach(ingest);
}

async function ensureItemsLoaded(ids) {
    return ensureLoaded(ids, 'items_by_ids', id => !ITEM_CACHE.has(id), item => {
        const id = Number(item.id || 0);

        if (id) {
            ITEM_CACHE.set(id, item);
        }
    });
}

async function ensureCardsLoaded(ids) {
    return ensureLoaded(ids, 'cards_by_ids', id => !FULL_CARD_IDS.has(id), markCardFull);
}

async function loadEquipmentServerCards(itemIds) {
    const ids = uniquePositiveIds(itemIds);

    if (!ids.length) {
        return;
    }

    // Серверные карты меняются только через админку —
    // дозапрашиваем только предметы, которых ещё нет в кэше.
    const missing = ids.filter(id => !EQUIPMENT_SERVER_CARDS.has(id));

    if (!missing.length) {
        return;
    }

    const data = await apiJson('equipment_cards', {
        item_ids: missing.join(','),
    });

    missing.forEach(id => {
        const entry = data[String(id)] || data[id] || {
            sockets: []
        };
        EQUIPMENT_SERVER_CARDS.set(id, Array.isArray(entry.sockets) ? entry.sockets : []);
    });

    Object.values(data || {}).forEach(entry => {
        (entry && Array.isArray(entry.sockets) ? entry.sockets : []).forEach(socket => {
            if (socket && socket.card) {
                markCardFull(socket.card);
            }
        });
    });
}

