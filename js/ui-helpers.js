let prevSelected = null;
let activeSlot = null;
let equipment = {};

let currentItems = [];
let currentCardsAll = [];
let cardsItemType = null;

let searchStale = false;
let modalUpgrade = 0;
let modalTab = 'items';
let activeSocketNumber = null;

function emptyItemCardsState() {
    return {
        loadedItemId: null,
        sockets: [],
        socketsCount: 0,
        itemTypeId: null,
        loading: false,
    };
}

let itemCardsState = emptyItemCardsState();

let lockedSlots = new Set();
let availableTypesBySlot = new Map();
let enabledFeatureCheckboxIds = new Set();

const genderSelect = document.getElementById('gender');
const level = document.getElementById('level');
const classSelect = document.getElementById('class');
const errBox = document.getElementById('db-error');

const modalOverlay = document.getElementById('modal-overlay');
const modalCenter = document.getElementById('modal-center');
const modalFeatures = document.getElementById('modal-features');
const modalSockets = document.getElementById('modal-sockets');
const modalTabs = document.getElementById('modal-tabs');

const searchInput = document.getElementById('item-search');
const upgradeSelect = document.getElementById('upgrade-level');
const stateSelect = document.getElementById('current-state');
const stampColorSelect = document.getElementById('stamp-color');
const stampColorField = document.getElementById('modal-stamp-color-field');

const REASONS = new Map();
const MISSING_REASONS = new Set();

function formatReasonTemplate(template, values = {}) {
    return String(template || '').replace(/\{(\w+)\}/g, (match, key) => {
        const value = values[key];

        if (value === null || value === undefined) {
            return '';
        }

        return String(value);
    });
}

function reason(key, values = {}) {
    const code = String(key);
    const template = REASONS.get(code);
    if (template === undefined) {
        if (!MISSING_REASONS.has(code)) {
            MISSING_REASONS.add(code);
            console.warn('Нет текста причины в enum_labels: reason/' + code);
        }
        return '';
    }
    return formatReasonTemplate(template, values);
}

function reasonNumberOrNull(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const raw = String(value).trim().replace(',', '.');

    if (raw === '' || !/^-?\d+(?:\.\d+)?$/.test(raw)) {
        return null;
    }

    const num = Number(raw);

    return Number.isFinite(num) ? num : null;
}

function reasonFormatNumber(value) {
    return formatNumber(value);
}

function getReasonStatName(id) {
    return Number(id || 0) ? getStatName(Number(id)) : '';
}

function reasonFeature(name) {
    return reason('feature', {
        name
    });
}

function reasonState(name) {
    return reason('state', {
        name
    });
}

function reasonStateInactive(name) {
    return reason('stateInactive', {
        name
    });
}

function reasonCard(name) {
    return reason('card', {
        name
    });
}

function reasonSetCount(required) {
    return reason('setCount', {
        required: Math.max(0, Number(required || 0)),
    });
}

function reasonMultiplyIdenticalCards(bonus) {
    const required = Math.max(1, Number(bonus && bonus.scaling_required_count || 1));
    const multiplier = reasonNumberOrNull(bonus && bonus.scaling_multiplier);

    return reason('multiplyIdenticalCards', {
        required,
        multiplier: multiplier === null ? '—' : reasonFormatNumber(multiplier),
    });
}

function buildUpgradeReason(bonus) {
    const type = String(bonus && bonus.scaling_type || 'none');
    const step = Math.max(1, Number(bonus && bonus.scaling_step || 1));
    const threshold = Math.max(0, Number(bonus && bonus.scaling_threshold || 0));

    switch (type) {
        case 'per_level':
            return reason('upgradePerLevel', {
                step
            });

        case 'per_level_above':
            return reason('upgradePerLevelAbove', {
                step,
                threshold
            });

        case 'once_above':
            return reason('upgradeOnceAbove', {
                threshold
            });

        case 'per_every_n_levels':
            return reason('upgradePerEveryNLevels', {
                step
            });

        default:
            return reason('upgradeGeneric');
    }
}

function buildStatDependencyReason(bonus) {
    const type = String(bonus && bonus.scaling_type || '');

    if (type === 'stat_threshold') {
        const source = getReasonStatName(bonus.stat_source_id);
        const threshold = Math.max(0, Number(bonus.stat_threshold || 0));
        const trueValue = reasonNumberOrNull(bonus.stat_value_true);

        const valueSuffix = trueValue === null ?
            '' :
            ', ' + reason('statValueSuffix', {
                value: reasonFormatNumber(trueValue)
            });

        return reason('statThreshold', {
            source,
            threshold,
            valueSuffix,
        });
    }

    if (type === 'per_stat') {
        const sources = Array.isArray(bonus.stat_sources) && bonus.stat_sources.length ?
            bonus.stat_sources :
            (
                Number(bonus.stat_source_id || 0) ? [{
                    stat_id: Number(bonus.stat_source_id),
                    step: Number(bonus.stat_step || 1)
                }] : []
            );

        if (!sources.length) {
            return reason('statGeneric');
        }

        const mode = String(bonus.stat_condition_mode || 'all') === 'any' ?
            'any' :
            'all';

        const parts = sources.map(source => {
            return reason('statSourceStep', {
                name: getReasonStatName(source.stat_id),
                step: Math.max(1, Number(source.step || 1)),
            });
        });

        return reason('statMultiple', {
            parts: parts.join(' ' + reason(mode === 'any' ? 'joinOr' : 'joinAnd') + ' '),
        });
    }

    return reason('statGeneric');
}

function reasonClassRestrictionByNames(names) {
    const list = Array.isArray(names) ?
        names.filter(Boolean) : [names].filter(Boolean);

    return reason('classRestriction', {
        classes: list.join(', '),
    });
}

function reasonItemTypeRestrictionByNames(names) {
    const list = Array.isArray(names) ?
        names.filter(Boolean) : [names].filter(Boolean);

    return reason('itemTypeRestriction', {
        types: list.join(', '),
    });
}

function reasonGeneric() {
    return reason('generic');
}

function formatNumber(value) {
    const n = Number(value);

    if (!Number.isFinite(n)) {
        return '0';
    }

    const rounded = Math.round(n * 100) / 100;
    return String(rounded);
}

function formatBonus(value, type) {
    const n = Number(value);

    if (n === 0) {
        return '';
    }

    const abs = formatNumber(Math.abs(n));
    const sign = n > 0 ? '+' : '-';
    const suffix = type === 'percent' ? '%' : '';

    return sign + abs + suffix;
}

function truncateTo3(value) {
    return Math.trunc(value * 1000) / 1000;
}

function formatBaseWithUpgrade(base, add) {
    const baseText = formatNumber(base);

    if (add === 0) {
        return baseText;
    }

    return baseText + ' + ' + formatNumber(add);
}

function numericOrNull(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const num = Number(value);

    return Number.isFinite(num) ? num : null;
}

function uniquePositiveIds(list) {
    return Array.from(new Set(
        (Array.isArray(list) ? list : [])
        .map(Number)
        .filter(id => Number.isFinite(id) && id > 0)
    ));
}

function normalizeIdList(value) {
    if (value === null || value === undefined || value === '') {
        return [];
    }

    if (Array.isArray(value)) {
        return value
            .map(item => Number(item && typeof item === 'object' ? item.id : item))
            .filter(id => Number.isFinite(id) && id > 0);
    }

    if (typeof value === 'object') {
        return Object.values(value)
            .map(item => Number(item && typeof item === 'object' ? item.id : item))
            .filter(id => Number.isFinite(id) && id > 0);
    }

    const id = Number(value);

    return Number.isFinite(id) && id > 0 ? [id] : [];
}

function getFeatureDisplayName(featureId) {
    const feature = FEATURES[Number(featureId || 0)];

    return feature && feature.name ?
        feature.name :
        'функции';
}

function getSelectedClassId() {
    return Number(getCharacter().classId || 0);
}

function getClassParentId(classId) {
    const id = Number(classId || 0);

    if (!id) {
        return null;
    }

    if (REFERENCE_NAMES.classParents.has(id)) {
        return REFERENCE_NAMES.classParents.get(id);
    }

    const info = Object.values(INFO || []).find(item => Number(item.id) === id);

    if (!info) {
        return null;
    }

    return info.parent_id === null || info.parent_id === undefined ?
        null :
        Number(info.parent_id);
}

function getClassAncestorIds(classId) {
    const ids = new Set();
    let current = Number(classId || 0);

    while (current > 0 && !ids.has(current)) {
        ids.add(current);
        current = getClassParentId(current);
    }

    return ids;
}

function getClassNameById(classId) {
    const id = Number(classId || 0);

    if (!id) {
        return '';
    }

    const dbName = REFERENCE_NAMES.classes.get(id);

    if (dbName) {
        return dbName;
    }

    const info = Object.values(INFO || []).find(item => Number(item.id) === id);

    if (info) {
        return String(info.name || info.title || info.label || info.class_name || ('класс #' + id));
    }

    return 'класс #' + id;
}

function getClassNamesByIds(classIds) {
    const ids = normalizeIdList(classIds);

    if (!ids.length) {
        return '';
    }

    return ids
        .map(getClassNameById)
        .filter(Boolean)
        .join(', ');
}

function isClassRestrictionActive(classIds) {
    const restrictedIds = normalizeIdList(classIds);

    if (!restrictedIds.length) {
        return true;
    }

    const selectedClassId = getSelectedClassId();

    if (!selectedClassId) {
        return false;
    }

    const selectedAncestors = getClassAncestorIds(selectedClassId);

    return restrictedIds.some(id => selectedAncestors.has(Number(id)));
}

function getItemTypeName(id) {
    const num = Number(id || 0);
    return REFERENCE_NAMES.itemTypes.get(num) || ('предмет #' + num);
}

function getCardName(id) {
    const num = Number(id || 0);
    return REFERENCE_NAMES.cards.get(num) || ('карта #' + num);
}

function getStatName(id) {
    const num = Number(id || 0);
    return REFERENCE_NAMES.stats.get(num) || ('характеристика #' + num);
}

function insertCharIntoSearch(char) {
    const start = typeof searchInput.selectionStart === 'number' ?
        searchInput.selectionStart :
        searchInput.value.length;

    const end = typeof searchInput.selectionEnd === 'number' ?
        searchInput.selectionEnd :
        searchInput.value.length;

    searchInput.value =
        searchInput.value.slice(0, start) +
        char +
        searchInput.value.slice(end);

    const caret = start + char.length;
    searchInput.setSelectionRange(caret, caret);

    searchInput.dispatchEvent(new Event('input', {
        bubbles: true
    }));
}

function isTextEntryTarget(target) {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.isContentEditable) {
        return true;
    }

    const tag = target.tagName;

    if (tag === 'TEXTAREA' || tag === 'SELECT') {
        return true;
    }

    if (tag === 'INPUT') {
        const nonTextTypes = [
            'checkbox',
            'radio',
            'range',
            'button',
            'submit',
            'reset',
            'file',
            'image',
            'color',
        ];

        return !nonTextTypes.includes(target.type);
    }

    return false;
}

function scrollSelectedIntoView() {
    if (modalOverlay.hidden || !modalCenter) {
        return;
    }

    requestAnimationFrame(() => {
        const selected = modalCenter.querySelector('.selected');

        if (!selected) {
            return;
        }

        const containerRect = modalCenter.getBoundingClientRect();
        const selectedRect = selected.getBoundingClientRect();

        if (selectedRect.top < containerRect.top) {
            modalCenter.scrollTop -= (containerRect.top - selectedRect.top) + 8;
        } else if (selectedRect.bottom > containerRect.bottom) {
            modalCenter.scrollTop += (selectedRect.bottom - containerRect.bottom) + 8;
        }
    });
}

function getDefaultStateId() {
    if (!STATES.size) {
        return null;
    }

    for (const state of STATES.values()) {
        if (state.is_default) {
            return Number(state.id);
        }
    }

    const first = STATES.values().next().value;

    return first ? Number(first.id) : null;
}

function normalizeCurrentStateId(value) {
    const id = Number(value || 0);

    if (!STATES.size) {
        return null;
    }

    if (id && STATES.has(id)) {
        return id;
    }

    return getDefaultStateId();
}

function saveCurrentStateId() {
    if (currentStateId === null || currentStateId === undefined) {
        storageRemove(CURRENT_STATE_STORAGE_KEY);
        return;
    }

    storageSet(CURRENT_STATE_STORAGE_KEY, String(currentStateId));
}

function renderStateSelect() {
    if (!stateSelect) {
        return;
    }

    stateSelect.textContent = '';

    if (!STATES.size) {
        currentStateId = null;
        stateSelect.disabled = true;
        return;
    }

    stateSelect.disabled = false;

    STATES.forEach(state => {
        const option = document.createElement('option');
        option.value = String(state.id);
        option.textContent = state.name + (state.is_default ? '' : '');
        stateSelect.appendChild(option);
    });

    currentStateId = normalizeCurrentStateId(currentStateId);
    stateSelect.value = currentStateId ? String(currentStateId) : '';

    saveCurrentStateId();
}

function getStateNameById(stateId) {
    const id = Number(stateId || 0);

    if (!id) {
        return '';
    }

    const state = STATES.get(id);

    return state ? state.name : ('состояние #' + id);
}

function isEntityStateActive(stateId) {
    const id = Number(stateId || 0);

    // Без состояния — всегда активно.
    if (!id) {
        return true;
    }

    // Если состояний нет, но у объекта осталось невалидное состояние,
    // считаем его неактивным.
    if (!STATES.size) {
        return false;
    }

    return Boolean(currentStateId && Number(currentStateId) === id);
}

function getStateInactiveReason(stateId) {
    const id = Number(stateId || 0);

    if (!id) {
        return '';
    }

    return reasonStateInactive(getStateNameById(id));
}

function isItemStateActive(item) {
    return isEntityStateActive(item && item.state_id);
}

function isCardStateActive(card) {
    return isEntityStateActive(card && card.state_id);
}

function getCalcItemForSavedItem(savedItem) {
    const itemId = Number(savedItem && savedItem.id ? savedItem.id : 0);

    if (!itemId) {
        return savedItem || null;
    }

    return ITEM_CACHE.get(itemId) || savedItem || null;
}

function isSavedItemActiveForCalc(savedItem) {
    const item = getCalcItemForSavedItem(savedItem);

    return item ? isItemStateActive(item) : false;
}

function makeStateNoticeStat(reason) {
    if (!reason) {
        return null;
    }

    return {
        isStateNotice: true,
        value: reason,
    };
}

function prependStateNotice(stats, reason) {
    const notice = makeStateNoticeStat(reason);

    if (notice) {
        stats.unshift(notice);
    }

    return stats;
}

function renderStatNote(parent, note) {
    if (!note) {
        return;
    }

    if (Array.isArray(note)) {
        const items = note.filter(Boolean);

        if (!items.length) {
            return;
        }

        const list = document.createElement('ul');
        list.className = 'item-stat-reasons';

        items.forEach(text => {
            const li = document.createElement('li');
            li.textContent = text;
            list.appendChild(li);
        });

        parent.appendChild(list);
        return;
    }

    const span = document.createElement('span');
    span.className = 'item-stat-note';
    span.textContent = note;
    parent.appendChild(span);
}

