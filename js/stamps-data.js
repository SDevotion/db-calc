function emptyItemStampState() {
    return {
        loadedItemId: null,
        itemTypeId: null,
        isLocked: false,
        variant: null,
        loading: false
    };
}
let itemStampState = emptyItemStampState();
/* Offline MathHelper.GetStampValue при minCoef=0, maxCoef=1.
   value = maxValue * (level - minLevel) / (maxLevel - minLevel);
   ceil, если ceil - raw < tolerance, иначе trunc. Сверху не клампится. */
function computeStampQualityValue(baseValue, internalLevel, minCoef, maxCoef) {
    const minLevel = settingInt('stamp_quality_min_level', 10);
    const maxLevel = settingInt('stamp_quality_max_level', 60);
    const tolerance = Number(SETTINGS.stamp_round_tolerance ?? 0.98);
    const span = maxLevel - minLevel;
    const above = Math.max(0, Number(internalLevel || 0) - minLevel);
    const coef = span > 0 ?
        Number(minCoef) + ((Number(maxCoef) - Number(minCoef)) / span) * above :
        Number(minCoef);
    const raw = Math.round(coef * Number(baseValue || 0) * 1e9) / 1e9;
    if (!Number.isFinite(raw)) {
        return 0;
    }
    const ceil = Math.ceil(raw);
    return (ceil - raw < tolerance) ? ceil : Math.trunc(raw);
}

function getStampInternalLevel(item, savedItem = null) {
    const source = item || savedItem || {};
    const internal = Number(source.internal_level || 0);
    if (internal > 0) {
        return internal;
    }
    const required = Number(source.required_level || 0);
    if (required > 0) {
        return required;
    }
    return settingInt('stamp_quality_min_level', 10);
}

function markStampVariantFull(variant) {
    mergeIntoCacheById(STAMP_VARIANTS, variant);
}

function getFullStampVariant(id, fallback = null) {
    const num = Number(id || 0);
    const known = num ? STAMP_VARIANTS.get(num) : null;
    if (known) {
        return known;
    }
    // Снапшот из localStorage не пишем в STAMP_VARIANTS: иначе удалённые
    // в админке варианты и их цвета воскресают в списках и в select.
    if (fallback && num) {
        return {
            ...fallback
        };
    }
    return null;
}

function makeStampSnapshot(variant) {
    return {
        id: Number(variant.id || 0),
        stamp_id: Number(variant.stamp_id || 0),
        name: variant.name || '',
        color_id: Number(variant.color_id || 0),
        color_name: variant.color_name || '',
        color_hex: variant.color_hex || '',
    };
}
async function loadStamps() {
    try {
        const rows = fromBootstrap('stamps') ?? await apiJson('stamps');
        STAMP_VARIANTS.clear();
        (Array.isArray(rows) ? rows : []).forEach(markStampVariantFull);
    } catch (e) {
        reportLoadError('печати', e);
    }
}
async function ensureStampsLoaded(ids) {
    return ensureLoaded(ids, 'stamps_by_ids', id => {
        const variant = STAMP_VARIANTS.get(id);
        return !variant || !Array.isArray(variant.bonuses);
    }, markStampVariantFull);
}
async function loadEquipmentServerStamps(itemIds) {
    const ids = uniquePositiveIds(itemIds);
    if (!ids.length) {
        EQUIPMENT_SERVER_STAMPS.clear();
        return;
    }
    const data = await apiJson('equipment_stamps', {
        item_ids: ids.join(',')
    });
    EQUIPMENT_SERVER_STAMPS.clear();
    ids.forEach(id => {
        const entry = data[String(id)] || data[id] || {
            is_locked: 0,
            variant: null
        };
        if (entry.variant) {
            markStampVariantFull(entry.variant);
        }
        EQUIPMENT_SERVER_STAMPS.set(id, {
            is_locked: Number(entry.is_locked || 0),
            variant: entry.variant || null,
        });
    });
}

function getActiveStampForEquippedItem(savedItem) {
    if (!savedItem) {
        return null;
    }
    const itemId = Number(savedItem.id || 0);
    if (!itemId) {
        return null;
    }
    const server = EQUIPMENT_SERVER_STAMPS.get(itemId) || null;
    const locked = server ? Number(server.is_locked || 0) === 1 : false;
    if (locked) {
        return server.variant ? getFullStampVariant(server.variant.id, server.variant) : null;
    }
    if (Object.prototype.hasOwnProperty.call(savedItem, 'user_stamp')) {
        const value = savedItem.user_stamp;
        if (!value) {
            return null;
        }
        if (typeof value === 'object') {
            return getFullStampVariant(value.id, value);
        }
        return getFullStampVariant(value, null);
    }
    return server && server.variant ? getFullStampVariant(server.variant.id, server.variant) : null;
}

function collectEquippedUserStampIds() {
    return collectIds(add => {
        Object.values(equipment || {}).forEach(savedItem => {
            add(savedValueId(savedItem && savedItem.user_stamp));
        });
    });
}

function collectActiveEquippedStampIds() {
    return collectIds(add => {
        Object.values(equipment || {}).forEach(savedItem => {
            if (!isSavedItemActiveForCalc(savedItem)) {
                return;
            }
            add(equippedEntityId(getActiveStampForEquippedItem(savedItem)));
        });
    });
}

function isStampAllowedForItemType(variant, itemTypeId) {
    const allowed = Array.isArray(variant && variant.allowed_item_type_ids) ? variant.allowed_item_type_ids : [];
    if (!allowed.length) {
        return true;
    }
    return allowed.map(Number).includes(Number(itemTypeId || 0));
}

function tabRequiresItem(slug) {
    return slug === 'cards' || slug === 'stamps';
}

function getGradeName(code) {
    const key = String(code || '').trim().toUpperCase();
    const row = GRADES.get(key);
    return row && row.name ? row.name : key;
}

