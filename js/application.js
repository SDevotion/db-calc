const API = 'api.php';

const EQUIPMENT_STORAGE_KEY = 'equipment_v2';
const FEATURE_CHECKBOXES_KEY = 'feature_checkboxes_v2';
const CURRENT_STATE_STORAGE_KEY = 'current_state_v1';
const CHARACTER_STORAGE_KEY = 'character_v1';

/* --- Безопасный localStorage: приватный режим / квота не роняют обработчики --- */
let storageWarned = false;

function storageSet(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        if (!storageWarned) {
            storageWarned = true;
            console.warn('localStorage недоступен — выбор не сохранится между сессиями.', e);
        }
    }
}

function storageGet(key) {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        return null;
    }
}

function storageRemove(key) {
    try {
        localStorage.removeItem(key);
    } catch (e) {
        /* noop */ }
}

/* --- Отмена устаревших запросов --- */
const REQUEST_CONTROLLERS = new Map();

function isAbortError(e) {
    return !!e && (e.name === 'AbortError' || e.code === 20);
}

function beginRequest(key) {
    const prev = REQUEST_CONTROLLERS.get(key);
    if (prev) {
        prev.abort();
    }
    const controller = new AbortController();
    REQUEST_CONTROLLERS.set(key, controller);
    return controller.signal;
}

function endRequest(key, signal) {
    const controller = REQUEST_CONTROLLERS.get(key);
    if (controller && controller.signal === signal) {
        REQUEST_CONTROLLERS.delete(key);
    }
}

function abortRequest(key) {
    const controller = REQUEST_CONTROLLERS.get(key);
    if (controller) {
        controller.abort();
        REQUEST_CONTROLLERS.delete(key);
    }
}
const REQUEST_TIMEOUT_MS = 15000;
async function fetchWithTimeout(url, options = {}) {
    const controller = new AbortController();
    const external = options.signal;
    const onAbort = () => controller.abort();
    if (external) {
        if (external.aborted) {
            controller.abort();
        } else {
            external.addEventListener('abort', onAbort, {
                once: true
            });
        }
    }
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    try {
        return await fetch(url, {
            ...options,
            signal: controller.signal
        });
    } catch (e) {
        if (controller.signal.aborted && !(external && external.aborted)) {
            throw new Error('Превышено время ожидания ответа сервера.');
        }
        throw e;
    } finally {
        clearTimeout(timer);
        if (external) {
            external.removeEventListener('abort', onAbort);
        }
    }
}

/* --- Ошибки загрузки справочников --- */
const ENUMS = {};

function enumRows(group, scope = '') {
    const rows = Array.isArray(ENUMS[group]) ? ENUMS[group] : [];
    return scope ?
        rows.filter(row => Array.isArray(row.scope) && row.scope.includes(scope)) :
        rows;
}

function applyEnumLabels(payload) {
    Object.keys(ENUMS).forEach(key => delete ENUMS[key]);
    if (payload && typeof payload === 'object') {
        Object.entries(payload).forEach(([group, rows]) => {
            ENUMS[group] = Array.isArray(rows) ? rows : [];
        });
    }
    REASONS.clear();
    MISSING_REASONS.clear();
    enumRows('reason').forEach(row => {
        REASONS.set(String(row.code), String(row.name || ''));
    });
}
async function loadEnumLabels() {
    try {
        applyEnumLabels(fromBootstrap('enum_labels') ?? await apiJson('enum_labels'));
        if (!REASONS.size) {
            reportLoadError('тексты причин', new Error('В enum_labels нет группы reason.'));
        }
    } catch (e) {
        reportLoadError('подписи кодов', e);
    }
}

function renderGenderSelect() {
    if (!genderSelect) {
        return;
    }
    const rows = enumRows('gender', 'character');
    if (!rows.length) {
        return;
    }
    const current = genderSelect.value;
    genderSelect.textContent = '';
    rows.forEach(row => genderSelect.appendChild(new Option(row.name, row.code)));
    genderSelect.value = rows.some(row => row.code === current) ? current : rows[0].code;
}
const SETTINGS = {
    max_sockets: 3,
    max_character_level: 65,
    max_set_cards: 64,
    upgrade_rounding: 'ceil',
    stamp_quality_min_level: 10,
    stamp_quality_max_level: 60,
    stamp_round_tolerance: 0.98,
    stamp_value_level: 65,
};

function settingInt(key, fallback) {
    const n = Number(SETTINGS[key]);
    return Number.isFinite(n) ? n : fallback;
}

function applySettings(rows) {
    if (!rows || typeof rows !== 'object') {
        return;
    }
    Object.keys(SETTINGS).forEach(key => {
        if (!Object.prototype.hasOwnProperty.call(rows, key)) {
            return;
        }
        SETTINGS[key] = typeof SETTINGS[key] === 'number' ?
            Number(rows[key]) :
            String(rows[key]);
    });
}

function roundUpgradeValue(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return 0;
    }
    switch (String(SETTINGS.upgrade_rounding || 'ceil')) {
        case 'floor':
            return Math.floor(n);
        case 'round':
            return Math.round(n);
        default:
            return Math.ceil(n);
    }
}
async function loadSettings() {
    try {
        applySettings(fromBootstrap('settings') ?? await apiJson('settings'));
    } catch (e) {
        reportLoadError('параметры', e);
    }
}
let BOOTSTRAP_DATA = null;
async function loadBootstrap() {
    BOOTSTRAP_DATA = await apiJson('bootstrap');
}

function fromBootstrap(key) {
    return BOOTSTRAP_DATA && Object.prototype.hasOwnProperty.call(BOOTSTRAP_DATA, key) ?
        BOOTSTRAP_DATA[key] :
        null;
}
const LOAD_ERRORS = [];

function showDbError(message) {
    if (!errBox) {
        return;
    }
    errBox.textContent = message;
    errBox.hidden = false;
}

function reportLoadError(label, error) {
    if (isAbortError(error)) {
        return;
    }
    if (!LOAD_ERRORS.includes(label)) {
        LOAD_ERRORS.push(label);
    }
    console.error('Не удалось загрузить ' + label + ':', error);
    showDbError('Не удалось загрузить: ' + LOAD_ERRORS.join(', ') + '. Проверьте соединение с БД и обновите страницу.');
}

/* --- Кэш контекста на один синхронный рендер --- */
let uiContext = null;

function beginUiContext() {
    uiContext = {
        counts: null,
        cardIds: null,
        setModes: new Map()
    };
}

function endUiContext() {
    uiContext = null;
}

/* --- Что реально отрисовано в modalCenter --- */
let renderedTab = null;
let renderedSignature = '';

function invalidateRender() {
    renderedTab = null;
    renderedSignature = '';
}

function markRendered(tab) {
    renderedTab = tab;
    renderedSignature = getRenderSignature();
}

function renderItems(items) {
    const next = Array.isArray(items) ? items : [];
    currentItems = next;
    if (modalOverlay.hidden) {
        return;
    }
    if (modalTab === 'cards') {
        invalidateRender();
        return;
    }
    beginUiContext();
    try {
        renderItemsInner(currentItems);
    } finally {
        endUiContext();
    }
    markRendered('items');
}

function renderCardsTab() {
    if (modalOverlay.hidden) {
        return;
    }
    // Вкладка могла переключиться, пока карты грузились.
    if (modalTab !== 'cards') {
        invalidateRender();
        renderModalContent(true);
        return;
    }
    beginUiContext();
    try {
        renderCardsTabInner();
    } finally {
        endUiContext();
    }
    markRendered('cards');
}

/* --- Сигнатура рендера (защита от циклов перерисовки) --- */
function getRenderSignature() {
    const count = currentItems.length;
    return [
        modalTab, activeSlot, activeSocketNumber, currentStateId, modalUpgrade, stampColorId,
        itemCardsState.loadedItemId, itemCardsState.socketsCount,
        itemCardsState.loading ? 1 : 0,
        count,
        count ? Number(currentItems[0].id || 0) : 0,
        count ? Number(currentItems[count - 1].id || 0) : 0,
        searchInput ? searchInput.value : '',
        Array.from(enabledFeatureCheckboxIds).sort().join(','),
        mapSignature(CALC_STATE.totals),
        JSON.stringify(equipment),
    ].join('|');
}

/* --- Запоминание персонажа --- */
/* --- Доступность --- */
let lastFocusedElement = null;

function getFocusableInModal() {
    if (!modalOverlay) {
        return [];
    }
    return Array.from(modalOverlay.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null);
}

function trapModalFocus(event) {
    if (event.key !== 'Tab' || !modalOverlay || modalOverlay.hidden) {
        return;
    }
    const items = getFocusableInModal();
    if (!items.length) {
        event.preventDefault();
        return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function initModalA11y() {
    if (!modalOverlay) {
        return;
    }
    const dialog = modalOverlay.querySelector('.modal');
    if (dialog) {
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', 'Выбор предмета');
        dialog.tabIndex = -1;
    }
    if (modalTabs) {
        modalTabs.setAttribute('role', 'tablist');
    }
    if (modalCenter) {
        modalCenter.setAttribute('role', 'tabpanel');
    }
    document.querySelectorAll('.slot').forEach(slotEl => {
        slotEl.setAttribute('aria-label', 'Ячейка ' + slotEl.dataset.slot);
        if (slotEl.tagName !== 'BUTTON') {
            slotEl.setAttribute('role', 'button');
            slotEl.tabIndex = 0;
            slotEl.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    slotEl.click();
                }
            });
        }
    });
    document.addEventListener('keydown', trapModalFocus);
}

function saveCharacterSelection() {
    storageSet(CHARACTER_STORAGE_KEY, JSON.stringify({
        gender: genderSelect.value,
        className: classSelect.value,
        level: Number(level.value) || 1,
    }));
}

function restoreCharacterSelection() {
    let saved = null;
    try {
        saved = JSON.parse(localStorage.getItem(CHARACTER_STORAGE_KEY));
    } catch (e) {
        saved = null;
    }
    const names = Object.keys(INFO);
    if (!names.length) {
        return false;
    }
    const className = saved && INFO[saved.className] ? saved.className : names[0];
    classSelect.value = className;
    prevSelected = className;
    const info = INFO[className];
    setLevelRange(info.min, info.max);
    let lvl = Number(saved && saved.level ? saved.level : info.min);
    if (!Number.isFinite(lvl)) {
        lvl = info.min;
    }
    level.value = Math.min(info.max, Math.max(info.min, lvl));
    if (saved && (saved.gender === 'male' || saved.gender === 'female')) {
        genderSelect.value = saved.gender;
    }
    return true;
}

const INFO = {};
const FEATURES = {};
const SLOT_RULES = [];
const TABS = [];
const TAB_MAP = new Map();

const CARD_SETS = new Map();

const STATES = new Map();
let currentStateId = null;

const REFERENCE_NAMES = {
    itemTypes: new Map(),
    cards: new Map(),
    stats: new Map(),
    classes: new Map(),
    classParents: new Map(),
};

const UPGRADES = new Map(); // level -> { attack_percent, defense_percent }
const GRADES = new Map(); // code -> { code, name, sort_order, max_upgrade_level }
const TYPE_HIDDEN_TABS = new Map(); // item_type_id -> Set(slug скрытых вкладок)
const STAMP_VARIANTS = new Map();
const EQUIPMENT_SERVER_STAMPS = new Map();
let currentStampsAll = [];
let stampsItemType = null;
let stampColorId = null;

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

const SEARCH_OPERATORS = new Set(['>', '<', '=', '>=', '<=']);

function parseSearchNumber(value) {
    const raw = String(value ?? '').trim().replace(',', '.');

    if (raw === '' || !/^-?\d+(?:\.\d+)?$/.test(raw)) {
        return null;
    }

    const num = Number(raw);

    return Number.isFinite(num) ? num : null;
}

function pushSearchCondition(terms, stat, operator, value) {
    const statText = String(stat || '').trim().toLowerCase();
    const num = parseSearchNumber(value);

    if (!statText || num === null || !SEARCH_OPERATORS.has(operator)) {
        return false;
    }

    terms.push({
        type: 'condition',
        stat: statText,
        operator,
        value: num,
    });

    return true;
}

function parseSearchQuery(raw) {
    const text = String(raw || '').trim();
    const terms = [];

    if (!text) {
        return {
            raw: text,
            terms,
            hasCondition: false,
        };
    }

    const conditionRe = /([^\s<>+=]+)\s*(>=|<=|>|<|=)\s*(-?\d+(?:[.,]\d+)?)/g;

    const rest = text.replace(conditionRe, (match, stat, operator, value) => {
        if (pushSearchCondition(terms, stat, operator, value)) {
            return ' ';
        }

        return match;
    });

    rest
        .split(/\s+/)
        .filter(Boolean)
        .forEach(word => {
            terms.push({
                type: 'text',
                text: word.toLowerCase(),
            });
        });

    return {
        raw: text,
        terms,
        hasCondition: terms.some(term => term.type === 'condition'),
    };
}

function searchStatMatchesCondition(stat, term) {
    const names = Array.isArray(stat?.names) ?
        stat.names.filter(Boolean).map(name => String(name).toLowerCase()) : [];

    if (!names.length || !term.stat) {
        return false;
    }

    if (!names.some(name => name.includes(term.stat))) {
        return false;
    }

    const rawValue = Number(stat.value || 0);
    const rawMax = stat.valueMax === null || stat.valueMax === undefined || stat.valueMax === '' ?
        null :
        Number(stat.valueMax);

    const value = Number.isFinite(rawValue) ? rawValue : 0;
    const max = rawMax !== null && Number.isFinite(rawMax) ? rawMax : value;

    const low = Math.min(value, max);
    const high = Math.max(value, max);
    const num = Number(term.value);

    if (!Number.isFinite(num)) {
        return false;
    }

    switch (term.operator) {
        case '>':
            return high > num;
        case '>=':
            return high >= num;
        case '<':
            return low < num;
        case '<=':
            return low <= num;
        case '=':
            return low <= num && num <= high;
        default:
            return false;
    }
}

function searchMatchesTerms(text, stats, parsed) {
    if (!parsed.terms.length) {
        return true;
    }

    if (!parsed.hasCondition && parsed.raw.trim().length < 3) {
        return true;
    }

    const lowerText = String(text || '').toLowerCase();

    return parsed.terms.every(term => {
        if (term.type === 'text') {
            return lowerText.includes(term.text);
        }

        return Array.isArray(stats) && stats.some(stat => searchStatMatchesCondition(stat, term));
    });
}

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

    const data = await apiJson('equipment_cards', {
        item_ids: ids.join(','),
    });

    ids.forEach(id => {
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

function isFeatureVisibleForTab(featureId, tab) {
    const id = Number(featureId || 0);

    if (!id) {
        return true;
    }

    const feature = FEATURES[id];

    if (!feature || feature.checkbox_id === null || feature.checkbox_id === undefined) {
        return false;
    }

    const tabId = Number(feature.tab_id || 0);

    if (tabId) {
        const tabObj = TAB_MAP.get(tabId);

        if (!tabObj || tabObj.slug !== tab) {
            return false;
        }
    }

    return enabledFeatureCheckboxIds.has(String(feature.checkbox_id));
}

function isFeatureVisible(featureId) {
    return isFeatureVisibleForTab(featureId, modalTab);
}

function isFeatureTabMatches(feature) {
    const tabId = Number(feature?.tab_id || 0);

    if (!tabId) {
        return true;
    }

    const tab = TAB_MAP.get(tabId);

    return Boolean(tab && tab.slug === modalTab);
}

function renderFeatureCheckboxes() {
    modalFeatures.textContent = '';

    const list = Object.values(FEATURES)
        .filter(f => f.checkbox_id !== null && f.checkbox_id !== undefined)
        .filter(isFeatureTabMatches)
        .sort((a, b) => Number(a.checkbox_id) - Number(b.checkbox_id));

    if (!list.length) {
        return;
    }

    list.forEach(feature => {
        const label = document.createElement('label');
        label.className = 'feature-toggle';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.checkboxId = String(feature.checkbox_id);
        input.checked = enabledFeatureCheckboxIds.has(String(feature.checkbox_id));

        const span = document.createElement('span');
        span.textContent = feature.name;

        label.appendChild(input);
        label.appendChild(span);
        modalFeatures.appendChild(label);
    });
}

async function loadFeatures() {
    try {
        const rows = fromBootstrap('features') ?? await apiJson('features');

        rows.forEach(r => {
            FEATURES[Number(r.id)] = {
                id: Number(r.id),
                checkbox_id: r.checkbox_id === null ? null : Number(r.checkbox_id),
                tab_id: r.tab_id === null ? null : Number(r.tab_id),
                name: r.name,
            };
        });

        renderFeatureCheckboxes();
    } catch (e) {
        reportLoadError('функции', e);
    }
}

function getDefaultModalTab() {
    const visible = getVisibleTabSlugs();
    if (visible.includes('items')) {
        return 'items';
    }
    if (visible.length) {
        return visible[0];
    }
    return TABS[0]?.slug || 'items';
}

function getSavedActiveItem() {
    return activeSlot !== null ? equipment[String(activeSlot)] : null;
}

function ensureValidModalTab() {
    const before = modalTab;
    const visible = getVisibleTabSlugs();
    if (!visible.includes(modalTab)) {
        modalTab = visible.includes('items') ? 'items' : (visible[0] || modalTab);
    }
    if (!TABS.some(tab => tab.slug === modalTab)) {
        modalTab = getDefaultModalTab();
    }
    if (tabRequiresItem(modalTab) && !getSavedActiveItem()) {
        modalTab = visible.includes('items') ? 'items' : (visible[0] || getDefaultModalTab());
    }
    if (before !== modalTab) {
        renderFeatureCheckboxes();
    }
}

function updateModalTabs() {
    if (!modalTabs) {
        return;
    }
    ensureValidModalTab();
    const saved = getSavedActiveItem();
    const canShowCards = !modalOverlay.hidden && Boolean(saved);
    const visible = getVisibleTabSlugs();
    modalTabs.querySelectorAll('button[data-modal-tab]').forEach(button => {
        const slug = button.dataset.modalTab;
        const selected = slug === modalTab;
        button.hidden = !visible.includes(slug);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.disabled = tabRequiresItem(slug) ? !canShowCards : false;
    });
}

function renderTabs() {
    if (!modalTabs) {
        return;
    }

    modalTabs.textContent = '';

    TABS.forEach(tab => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.modalTab = tab.slug;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-selected', tab.slug === modalTab ? 'true' : 'false');
        button.textContent = tab.name;

        modalTabs.appendChild(button);
    });

    updateModalTabs();
}

async function loadTabs() {
    try {
        const rows = fromBootstrap('modal_tabs') ?? await apiJson('modal_tabs');

        TABS.length = 0;
        TAB_MAP.clear();

        (Array.isArray(rows) ? rows : []).forEach(r => {
            const tab = {
                id: Number(r.id),
                slug: String(r.slug),
                name: String(r.name),
            };
            TABS.push(tab);
            TAB_MAP.set(tab.id, tab);
        });
    } catch (e) {
        reportLoadError('вкладки', e);
    }

    if (!TABS.length) {
        reportLoadError('вкладки', new Error('Таблица modal_tabs пустая.'));
    }

    if (!TABS.some(tab => tab.slug === modalTab)) {
        modalTab = getDefaultModalTab();
    }

    renderTabs();
}

function renderModalContent(force = false) {
    if (modalOverlay.hidden) {
        return;
    }
    ensureValidModalTab();
    updateModalTabs();
    const signature = getRenderSignature();
    if (!force && renderedTab === modalTab && signature === renderedSignature) {
        return;
    }
    if (modalTab === 'cards') {
        renderCardsTab();
    } else if (modalTab === 'stamps') {
        renderStampsTab();
    } else {
        renderItems(currentItems);
    }
}

function getMaxDefinedUpgrade() {
    let max = 0;

    for (const upgradeLevel of UPGRADES.keys()) {
        if (upgradeLevel > max) {
            max = upgradeLevel;
        }
    }

    return max;
}

function getUpgradePercent(upgradeLevel, type) {
    const upgrade = UPGRADES.get(Number(upgradeLevel));

    if (!upgrade) {
        return 0;
    }

    return Number(upgrade[type] || 0);
}

function getMaxUpgradeForGrade(grade) {
    const definedMax = getMaxDefinedUpgrade();
    const key = String(grade || '').trim().toUpperCase();
    if (!key) {
        return 0;
    }
    const row = GRADES.get(key);
    if (!row || row.max_upgrade_level === null) {
        return definedMax;
    }
    return Math.max(0, Math.min(Number(row.max_upgrade_level || 0), definedMax));
}

function normalizeUpgradeForItem(item, value) {
    if (!item || !Number(item.can_upgrade)) {
        return 0;
    }

    const max = getMaxUpgradeForGrade(item.grade);
    let n = Number(value || 0);

    if (!Number.isFinite(n) || n < 0) {
        n = 0;
    }

    if (n > max) {
        n = max;
    }

    return n;
}

function disableUpgradeControl() {
    upgradeSelect.disabled = true;
    upgradeSelect.textContent = '';
    upgradeSelect.appendChild(new Option('0', '0'));
    upgradeSelect.value = '0';
}

function updateUpgradeControlForSelected() {
    const saved = activeSlot !== null ? equipment[String(activeSlot)] : null;

    if (!saved) {
        modalUpgrade = 0;
        disableUpgradeControl();
        return;
    }

    const currentItem = currentItems.find(item => Number(item.id) === Number(saved.id));
    const source = currentItem || saved;

    const canUpgrade = Number(source.can_upgrade || 0) === 1;

    if (!canUpgrade) {
        if (Number(saved.upgrade || 0) !== 0) {
            saved.upgrade = 0;
            saveEquipment();
        }

        const maxLevel = getMaxDefinedUpgrade();

        upgradeSelect.textContent = '';

        for (let i = 0; i <= maxLevel; i++) {
            upgradeSelect.appendChild(new Option(String(i), String(i)));
        }

        const displayValue = Math.max(0, Math.min(maxLevel, Number(modalUpgrade || 0)));
        upgradeSelect.value = String(displayValue);
        upgradeSelect.disabled = true;

        return;
    }

    const max = getMaxUpgradeForGrade(source.grade);

    if (!Number.isFinite(modalUpgrade) || modalUpgrade < 0) {
        modalUpgrade = 0;
    }

    if (modalUpgrade > max) {
        modalUpgrade = max;
    }

    if (Number(saved.upgrade || 0) !== modalUpgrade) {
        saved.upgrade = modalUpgrade;
        saveEquipment();
    }

    upgradeSelect.textContent = '';

    for (let i = 0; i <= max; i++) {
        upgradeSelect.appendChild(new Option(String(i), String(i)));
    }

    upgradeSelect.value = String(modalUpgrade);
    upgradeSelect.disabled = false;
}

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
    const rules = new Map();
    const info = INFO[classSelect.value];

    if (!info) {
        return rules;
    }

    const classIds = [];

    if (info.parent_id) {
        classIds.push(info.parent_id);
    }

    classIds.push(info.id);

    classIds.forEach(classId => {
        SLOT_RULES.forEach(rule => {
            if (rule.slot_number === slotNumber && rule.class_id === classId) {
                rules.set(rule.item_type_id, rule.restriction);
            }
        });
    });

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
        await refreshCardsView(true);
    } else if (modalTab === 'stamps') {
        await refreshStampsView(true);
    } else {
        renderModalContent();
    }
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
        const saved = equipment[String(slot)];
        modalUpgrade = saved ? Number(saved.upgrade || 0) : 0;
        invalidateRender();
        renderItems(items);
        if (modalTab === 'cards') {
            await refreshCardsView(true);
        } else if (modalTab === 'stamps') {
            await refreshStampsView(true);
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

    return entries.filter(entry => searchMatchesTerms(getText(entry), getStats(entry), parsed));
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
    return [
        variant.name || '',
        variant.color_name || '',
        ...(variant.bonuses || []).map(bonus => bonus.name_ru),
        ...(variant.bonuses || []).map(bonus => bonus.stat_alias),
    ].filter(Boolean).join(' ').toLowerCase();
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
        return searchMatchesTerms(getStampSearchText(variant), getStampSearchStats(variant), parsed);
    });
}

function createStampCard(variant, options = {}) {
    const template = document.getElementById('item-card-template');
    const cardEl = template.content.firstElementChild.cloneNode(true);
    cardEl.querySelector('.item-icon-wrap')?.remove();
    cardEl.querySelector('.item-icon')?.remove();
    cardEl.querySelector('.item-icon-fallback')?.remove();
    const statsList = cardEl.querySelector('.item-stats');
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
    const context = getStampContext();
    let stateReason = '';
    if (!context.hostItemActive) {
        stateReason = context.hostItemStateId ?
            getStateInactiveReason(context.hostItemStateId) :
            'Не участвует в расчётах: предмет не активен';
    }
    const stats = getStampStats(variant, context, stateReason);
    renderStatsList(statsList, stats);
    if (options.selected) {
        cardEl.classList.add('selected');
    }
    if (!context.hostItemActive) {
        cardEl.classList.add('item-inactive');
    }
    if (options.selectable && typeof options.onClick === 'function') {
        cardEl.addEventListener('click', () => options.onClick(variant));
    } else {
        cardEl.disabled = true;
    }
    return cardEl;
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
            const grid = document.createElement('div');
            grid.className = 'item-grid';
            grid.appendChild(createStampCard(state.variant, {
                selected: true,
                selectable: false
            }));
            modalCenter.appendChild(grid);
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
    const selectedId = state.variant ? Number(state.variant.id) : null;
    const grid = document.createElement('div');
    grid.className = 'item-grid';
    filtered.forEach(group => {
        const variant = resolveStampGroupVariant(group, state);
        grid.appendChild(createStampCard(variant, {
            selectable: true,
            selected: selectedId !== null && Number(variant.id) === selectedId,
            onClick: selectStampVariant,
        }));
    });
    modalCenter.appendChild(grid);
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

function isCardCompatibleWithSocket(card, socketNumber) {
    const cardSocket = card.card_type_socket_number;

    if (cardSocket === null || cardSocket === undefined || cardSocket === '') {
        return true;
    }

    return Number(cardSocket) === Number(socketNumber);
}

function getSocketStates() {
    const saved = getSavedActiveItem();

    if (!saved) {
        return [];
    }

    const socketsCount = Number(itemCardsState.socketsCount || saved.sockets || 0);
    const serverBySocket = new Map();

    (itemCardsState.sockets || []).forEach(socket => {
        serverBySocket.set(Number(socket.socket_number), socket);
    });

    const userCards = saved.user_cards && typeof saved.user_cards === 'object' ?
        saved.user_cards : {};

    const states = [];

    for (let socket = 1; socket <= socketsCount; socket++) {
        const server = serverBySocket.get(socket) || null;
        const locked = server ? Number(server.is_locked || 0) === 1 : false;
        const key = String(socket);
        const hasUser = Object.prototype.hasOwnProperty.call(userCards, key);
        const userValue = userCards[key];

        let card = null;

        if (locked) {
            card = server && server.card ?
                getFullCard(server.card.id, server.card) :
                null;
        } else if (hasUser) {
            if (userValue && typeof userValue === 'object') {
                card = getFullCard(userValue.id, userValue);
            } else if (userValue) {
                card = getFullCard(userValue, null);
            } else {
                card = null;
            }
        } else {
            card = server && server.card ?
                getFullCard(server.card.id, server.card) :
                null;
        }

        states.push({
            socket_number: socket,
            locked,
            card,
            hasUser,
            userValue,
            serverCard: server && server.card ? server.card : null,
        });
    }

    return states;
}

function ensureActiveSocket() {
    const states = getSocketStates();

    if (!states.length) {
        activeSocketNumber = null;
        return;
    }

    const currentValid = states.some(state => state.socket_number === activeSocketNumber);

    if (currentValid) {
        return;
    }

    const freeUnlocked = states.filter(state => !state.locked && !state.card);

    if (freeUnlocked.length) {
        activeSocketNumber = freeUnlocked[0].socket_number;
        return;
    }

    const occupiedUnlocked = states.filter(state => !state.locked && state.card);

    if (occupiedUnlocked.length) {
        activeSocketNumber = occupiedUnlocked[0].socket_number;
        return;
    }

    activeSocketNumber = states[0].socket_number;
}

function advanceActiveSocketFrom(previousSocket) {
    const states = getSocketStates();

    if (!states.length) {
        activeSocketNumber = null;
        return;
    }

    const freeUnlocked = states.filter(state =>
        !state.locked &&
        !state.card &&
        state.socket_number !== Number(previousSocket)
    );

    if (freeUnlocked.length) {
        activeSocketNumber = freeUnlocked[0].socket_number;
        return;
    }

    const occupiedUnlocked = states.filter(state => !state.locked && state.card);

    if (occupiedUnlocked.length) {
        activeSocketNumber = occupiedUnlocked[0].socket_number;
        return;
    }

    activeSocketNumber = states[0].socket_number;
}

function renderSockets() {
    if (!modalSockets) {
        return;
    }
    if (modalTab !== 'stamps' && stampColorField) {
        stampColorField.hidden = true;
    }
    if (modalTab === 'stamps') {
        renderStampPanel();
        return;
    }
    const saved = getSavedActiveItem();

    if (modalOverlay.hidden || modalTab !== 'cards' || !saved) {
        modalSockets.hidden = true;
        modalSockets.textContent = '';
        return;
    }

    modalSockets.textContent = '';
    modalSockets.hidden = false;

    if (itemCardsState.loading) {
        const loading = document.createElement('div');
        loading.className = 'socket-empty';
        loading.textContent = 'Загрузка слотов...';
        modalSockets.appendChild(loading);
        return;
    }

    const states = getSocketStates();

    if (!states.length) {
        const empty = document.createElement('div');
        empty.className = 'socket-empty';
        empty.textContent = 'У предмета нет слотов.';
        modalSockets.appendChild(empty);
        return;
    }

    states.forEach(state => {
        const chip = document.createElement('div');
        chip.className = 'socket-chip';
        chip.dataset.socket = String(state.socket_number);

        if (state.socket_number === activeSocketNumber) {
            chip.classList.add('active');
        }

        if (state.locked) {
            chip.classList.add('forced');
            chip.title = 'Принудительная карта. Замена невозможна.';
        } else {
            chip.title = state.card ?
                'Выбрать слот для замены карты' :
                'Выбрать слот для установки карты';
        }

        chip.tabIndex = 0;

        const main = document.createElement('div');
        main.className = 'socket-chip-main';

        if (state.card && state.card.icon_path) {
            const icon = document.createElement('img');
            icon.className = 'socket-icon';
            icon.src = state.card.icon_path;
            icon.alt = state.card.name || '';
            icon.addEventListener('error', () => icon.remove());
            main.appendChild(icon);
        }

        const text = document.createElement('div');
        text.className = 'socket-text';

        const title = document.createElement('span');
        title.className = 'socket-title';
        title.textContent = 'Слот #' + state.socket_number;

        const cardName = document.createElement('span');
        cardName.className = 'socket-card';
        cardName.textContent = state.card ?
            state.card.name :
            (state.locked ? 'нет карты' : 'Пусто');

        text.appendChild(title);
        text.appendChild(cardName);
        main.appendChild(text);
        chip.appendChild(main);

        chip.addEventListener('click', () => setSocketActive(state.socket_number));
        chip.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setSocketActive(state.socket_number);
            }
        });

        if (!state.locked && state.card) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'socket-remove';
            remove.textContent = '×';
            remove.title = 'Снять карту';

            remove.addEventListener('click', event => {
                event.stopPropagation();
                removeSocketCard(state.socket_number);
            });

            chip.appendChild(remove);
        }

        modalSockets.appendChild(chip);
    });
}

function setSocketActive(socketNumber) {
    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(socketNumber));

    if (!state) {
        return;
    }

    activeSocketNumber = Number(socketNumber);
    renderSockets();
    renderCardsTab();
}

function removeSocketCard(socketNumber) {
    const saved = getSavedActiveItem();

    if (!saved) {
        return;
    }

    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(socketNumber));

    if (!state || state.locked || !state.card) {
        return;
    }

    if (!saved.user_cards || typeof saved.user_cards !== 'object') {
        saved.user_cards = {};
    }

    saved.user_cards[String(socketNumber)] = null;

    saveEquipment();
    ensureActiveSocket();
    renderSockets();
    renderCardsTab();
}

function selectCardForActiveSocket(card) {
    if (activeSocketNumber === null) {
        return;
    }

    const saved = getSavedActiveItem();

    if (!saved) {
        return;
    }

    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(activeSocketNumber));

    if (!state || state.locked) {
        return;
    }

    if (state.card && Number(state.card.id) === Number(card.id)) {
        removeSocketCard(activeSocketNumber);
        return;
    }

    const wasEmpty = !state.card;

    if (!saved.user_cards || typeof saved.user_cards !== 'object') {
        saved.user_cards = {};
    }

    saved.user_cards[String(activeSocketNumber)] = makeCardSnapshot(card);

    saveEquipment();

    if (wasEmpty) {
        advanceActiveSocketFrom(activeSocketNumber);
    } else {
        ensureActiveSocket();
    }

    renderSockets();
    renderCardsTab();
}

function computeEquippedItemTypeCounts() {
    const counts = new Map();
    Object.values(equipment || {}).forEach(savedItem => {
        if (!isSavedItemActiveForCalc(savedItem)) {
            return;
        }
        const itemId = Number(savedItem && savedItem.id ? savedItem.id : 0);
        const cached = itemId ? ITEM_CACHE.get(itemId) : null;
        const typeId = Number(
            (cached && cached.item_type_id ? cached.item_type_id : 0) ||
            (savedItem && savedItem.type_id ? savedItem.type_id : 0)
        );
        if (!typeId) {
            return;
        }
        counts.set(typeId, (counts.get(typeId) || 0) + 1);
    });
    return counts;
}

function getEquippedItemTypeCounts() {
    if (!uiContext) {
        return computeEquippedItemTypeCounts();
    }
    if (!uiContext.counts) {
        uiContext.counts = computeEquippedItemTypeCounts();
    }
    return uiContext.counts;
}

function getKnownEquippedCardIds() {
    if (uiContext && uiContext.cardIds) {
        return uiContext.cardIds;
    }
    const ids = new Set();
    forEachActiveEquippedCard(card => {
        const id = Number(card && card.id ? card.id : 0);
        if (id) {
            ids.add(id);
        }
    });
    if (!modalOverlay.hidden && activeSlot !== null) {
        const saved = getSavedActiveItem();
        if (saved && isSavedItemActiveForCalc(saved)) {
            getSocketStates().forEach(state => {
                if (state.card && isCardStateActive(state.card)) {
                    ids.add(Number(state.card.id));
                }
            });
        }
    }
    if (uiContext) {
        uiContext.cardIds = ids;
    }
    return ids;
}

function getUpgradeLevelForItem(item) {
    const itemId = Number(item && item.id ? item.id : 0);

    if (!itemId) {
        return 0;
    }

    const saved = getSavedActiveItem();

    if (saved && Number(saved.id) === itemId) {
        return normalizeUpgradeForItem(item, Number(modalUpgrade || 0));
    }

    for (const equippedItem of Object.values(equipment)) {
        if (equippedItem && Number(equippedItem.id) === itemId) {
            return Number(equippedItem.upgrade || 0);
        }
    }

    return 0;
}

function addHiddenReason(reasons, text) {
    if (text && !reasons.includes(text)) {
        reasons.push(text);
    }
}

function getItemTypeConditionText(conditions, mode) {
    const parts = (Array.isArray(conditions) ? conditions : [])
        .map(condition => {
            const name = getItemTypeName(condition.item_type_id);
            const count = Math.max(1, Number(condition.required_count || 1));
            if (count > 1) {
                return name + ' (' + reason('itemCountSuffix', {
                    count
                }) + ')';
            }
            return name;
        });
    if (parts.length > 1) {
        return reason(mode === 'any' ? 'conditionsAnyPrefix' : 'conditionsAllPrefix') +
            ': ' + parts.join(', ');
    }
    return parts.join(', ');
}

function computeSetNegativeMode(id, setStates) {
    const set = CARD_SETS.get(id);
    if (!set) {
        return 'allow';
    }
    if (Array.isArray(set.bonuses) && set.bonuses.length) {
        return 'allow';
    }
    let states = setStates instanceof Map ? setStates : null;
    if (!states && window.CALC_STATE && window.CALC_STATE.sets instanceof Map) {
        states = window.CALC_STATE.sets;
    }
    if (!states || !states.size) {
        states = refreshSetStatesSync();
    }
    const state = states.get(id);
    const mainCount = Math.max(0, Number(state && state.main_count || 0));
    const threshold = Math.max(0, Number(set.negative_threshold || 0));
    if (mainCount === 0) {
        return 'allow';
    }
    return mainCount < threshold ?
        String(set.negative_below_mode || 'allow') :
        String(set.negative_reached_mode || 'allow');
}

function getSetNegativeMode(setId, setStates = null) {
    const id = Number(setId || 0);
    if (!id) {
        return 'allow';
    }
    if (!setStates && uiContext && uiContext.setModes.has(id)) {
        return uiContext.setModes.get(id);
    }
    const mode = computeSetNegativeMode(id, setStates);
    if (!setStates && uiContext) {
        uiContext.setModes.set(id, mode);
    }
    return mode;
}

function resolveBonus(bonus, context, options = {}) {
    const mode = options.mode === 'calc' ? 'calc' : 'ui';

    const reasons = [];
    const reasonCodes = [];
    const hints = [];

    let active = true;
    let neutralized = false;

    const addReasonCode = code => {
        if (code && !reasonCodes.includes(code)) {
            reasonCodes.push(code);
        }
    };

    const fail = (code, uiMessage = '') => {
        active = false;
        addReasonCode(code);

        if (mode === 'ui' && uiMessage) {
            addHiddenReason(reasons, uiMessage);
        }
    };

    const addHint = message => {
        if (message && !hints.includes(message)) {
            hints.push(message);
        }
    };

    const baseValue = Number(bonus.value || 0);
    const baseValueMax = numericOrNull(bonus.value_max);

    let value = baseValue;
    let valueMax = baseValueMax;

    const scalingType = String(bonus.scaling_type || 'none');
    const upgradeLevel = Math.max(0, Number(context.upgradeLevel || 0));
    const tab = String(context.tab || modalTab || 'items');

    if (context.kind === 'set') {
        const required = Math.max(0, Number(context.setRequiredCount || 0));
        const ordinary = Math.max(0, Number(context.setOrdinaryCount || 0));

        if (!context.setHasMain || ordinary < required) {
            fail(
                'set_count',
                reasonSetCount(required)
            );
        }
    }

    if (!isEntityStateActive(bonus.state_id)) {
        fail(
            'state',
            reasonState(getStateNameById(bonus.state_id))
        );
    }

    if (!isFeatureVisibleForTab(bonus.feature_id, tab)) {
        fail(
            'feature',
            reasonFeature(getFeatureDisplayName(bonus.feature_id))
        );
    }

    if (bonus.depends_on_card_id) {
        const depId = Number(bonus.depends_on_card_id || 0);

        const knownCardIds = context.knownCardIds instanceof Set ?
            context.knownCardIds :
            getKnownEquippedCardIds();

        if (!knownCardIds.has(depId)) {
            fail(
                'depends_on_card',
                reasonCard(getCardName(depId))
            );
        }
    }

    const bonusClassIds = normalizeIdList(bonus.class_ids);

    if (bonusClassIds.length && !isClassRestrictionActive(bonusClassIds)) {
        fail(
            'class',
            reasonClassRestrictionByNames(getClassNamesByIds(bonusClassIds))
        );
    }

    if (
        context.kind === 'card' &&
        Array.isArray(bonus.item_type_restrictions) &&
        bonus.item_type_restrictions.length
    ) {
        const allowedTypeIds = bonus.item_type_restrictions.map(Number);

        if (!allowedTypeIds.includes(Number(context.itemTypeId || 0))) {
            fail(
                'item_type_restriction',
                reasonItemTypeRestrictionByNames(
                    bonus.item_type_restrictions.map(getItemTypeName)
                )
            );
        }
    }

    if (Array.isArray(bonus.item_type_conditions) && bonus.item_type_conditions.length) {
        const counts = context.equippedItemTypeCounts instanceof Map ?
            context.equippedItemTypeCounts :
            getEquippedItemTypeCounts();

        const conditionMode = String(bonus.item_type_condition_mode || 'all');

        const results = bonus.item_type_conditions.map(condition => {
            const typeId = Number(condition.item_type_id || 0);
            const required = Math.max(1, Number(condition.required_count || 1));
            const actual = Number(counts.get(typeId) || 0);

            return actual >= required;
        });

        const conditionsActive = conditionMode === 'any' ?
            results.some(Boolean) :
            results.every(Boolean);

        if (!conditionsActive) {
            fail(
                'item_type_conditions',
                reason('itemTypeConditions', {
                    conditions: getItemTypeConditionText(
                        bonus.item_type_conditions,
                        conditionMode
                    ),
                })
            );
        }
    }

    const override = context.itemTypeId ?
        findCardBonusOverride(bonus, context.itemTypeId) :
        null;

    if (override) {
        value = Number(override.value || 0);
        valueMax = numericOrNull(override.value_max);
    }

    let factor = 1;
    if (scalingType === 'range') {
        factor = 1;
    } else if (scalingType === 'stamp_quality') {
        value = computeStampQualityValue(
            Number(bonus.quality_value ?? baseValue),
            Number(context.internalLevel || 0),
            Number(bonus.stamp_min_coef ?? 1),
            Number(bonus.stamp_max_coef ?? 1)
        );
        factor = 1;
    } else if (scalingType === 'per_level' || scalingType === 'per_every_n_levels') {
        const step = Math.max(1, Number(bonus.scaling_step || 1));
        factor = Math.floor(upgradeLevel / step);
        value = value * factor;

        if (factor <= 0) {
            fail('upgrade', buildUpgradeReason(bonus));
        }
    } else if (scalingType === 'per_level_above') {
        const step = Math.max(1, Number(bonus.scaling_step || 1));
        const threshold = Math.max(0, Number(bonus.scaling_threshold || 0));
        factor = Math.floor(Math.max(0, upgradeLevel - threshold) / step);
        value = value * factor;

        if (factor <= 0) {
            fail('upgrade', buildUpgradeReason(bonus));
        }
    } else if (scalingType === 'once_above') {
        const threshold = Math.max(0, Number(bonus.scaling_threshold || 0));
        factor = upgradeLevel > threshold ? 1 : 0;
        value = value * factor;

        if (factor <= 0) {
            fail('upgrade', buildUpgradeReason(bonus));
        }
    } else if (scalingType === 'multiply_by_identical_cards') {
        const required = Math.max(1, Number(bonus.scaling_required_count || 1));
        const multiplier = numericOrNull(bonus.scaling_multiplier);

        const count = context.cardId && typeof window.getEquippedIdenticalCardCount === 'function' ?
            Number(window.getEquippedIdenticalCardCount(context.cardId) || 0) :
            0;

        if (multiplier !== null && count >= required) {
            value = value * multiplier;
            factor = multiplier;
        } else {
            factor = 1;
            addHint(reasonMultiplyIdenticalCards(bonus));
        }
    } else if (scalingType === 'per_stat') {
        const totals = context.totals instanceof Map ?
            context.totals :
            (window.CALC_STATE && window.CALC_STATE.ready ? window.CALC_STATE.totals : null);

        if (!totals) {
            value = 0;
            factor = 0;
            fail('stat', buildStatDependencyReason(bonus));
        } else {
            const sources = Array.isArray(bonus.stat_sources) && bonus.stat_sources.length ?
                bonus.stat_sources :
                (
                    Number(bonus.stat_source_id || 0) ? [{
                        stat_id: Number(bonus.stat_source_id),
                        step: Number(bonus.stat_step || 1)
                    }] : []
                );

            if (!sources.length) {
                value = 0;
                factor = 0;
                fail('stat', buildStatDependencyReason(bonus));
            } else {
                const factors = sources.map(source => {
                    const sourceId = Number(source.stat_id || 0);
                    const sourceTotal = Math.round(Math.max(0, Number(totals.get(sourceId) || 0)) * 1e6) / 1e6;
                    const step = Math.max(1, Number(source.step || 1));

                    return Math.floor(sourceTotal / step);
                });

                const mode = String(bonus.stat_condition_mode || 'all') === 'any' ?
                    'any' :
                    'all';

                if (mode === 'any') {
                    factor = factors.reduce((sum, current) => sum + current, 0);
                } else {
                    factor = Math.min(...factors);
                }

                value = value * factor;

                if (factor <= 0) {
                    fail('stat', buildStatDependencyReason(bonus));
                }
            }
        }
    } else if (scalingType === 'stat_threshold') {
        const totals = context.totals instanceof Map ?
            context.totals :
            (window.CALC_STATE && window.CALC_STATE.ready ? window.CALC_STATE.totals : null);

        const sourceId = Number(bonus.stat_source_id || 0);
        const threshold = Math.max(0, Number(bonus.stat_threshold || 0));
        const trueValue = numericOrNull(bonus.stat_value_true);
        const baseThresholdValue = value;

        if (!totals) {
            value = baseThresholdValue;
            factor = 1;
            addHint(buildStatDependencyReason(bonus));
        } else {
            const sourceTotal = Math.round(Math.max(0, Number(totals.get(sourceId) || 0)) * 1e6) / 1e6;

            if (trueValue !== null && sourceTotal >= threshold) {
                value = trueValue;
                factor = 1;
            } else {
                value = baseThresholdValue;
                factor = 1;
                addHint(buildStatDependencyReason(bonus));
            }
        }
    }

    if (
        context.kind === 'card' &&
        typeof context.setNegativeMode === 'string' &&
        value < 0 &&
        active
    ) {
        if (context.setNegativeMode === 'ignore') {
            value = 0;
            neutralized = true;
            active = false;
            addReasonCode('neutralized');
        } else if (context.setNegativeMode === 'absolute') {
            value = Math.abs(value);
        }
    }

    if (
        !neutralized &&
        active &&
        scalingType !== 'range' &&
        scalingType !== 'stat_threshold' &&
        value === 0
    ) {
        active = false;
        addReasonCode('zero');

        if (mode === 'ui' && !reasons.length) {
            addHiddenReason(reasons, reasonGeneric());
        }
    }

    return {
        active,
        neutralized,
        reasons,
        reasonCodes,
        hints,
        value,
        valueMax,
        baseValue,
        baseValueMax,
        scalingType,
        factor,
        override,
    };
}

function resolveBonusContext(bonus, context) {
    return resolveBonus(bonus, context, {
        mode: 'ui'
    });
}

function resolveBonusForCalculation(bonus, context, totals) {
    return resolveBonus(
        bonus, {
            ...context,
            totals,
        }, {
            mode: 'calc'
        }
    );
}

function formatResolvedBonusValue(resolved, bonus) {
    const suffix = bonus.value_type === 'percent' ? '%' : '';

    if (
        bonus.scaling_type === 'range' &&
        resolved.valueMax !== null &&
        resolved.valueMax !== undefined
    ) {
        return formatNumber(resolved.value) + suffix + ' - ' + formatNumber(resolved.valueMax) + suffix;
    }

    return formatBonus(resolved.value, bonus.value_type);
}

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

if (modalTabs) {
    modalTabs.addEventListener('click', async event => {
        const button = event.target.closest('button[data-modal-tab]');
        if (!button || button.disabled) {
            return;
        }
        const nextTab = button.dataset.modalTab;
        if (!nextTab) {
            return;
        }
        // Клик по активной вкладке тоже должен уметь перерисовать контент,
        // если в modalCenter сейчас лежит не то, что выбрано.
        if (nextTab === modalTab && renderedTab === modalTab) {
            return;
        }
        modalTab = nextTab;
        searchInput.value = '';
        searchStale = false;
        cardsViewToken++;
        invalidateRender();
        ensureValidModalTab();
        renderFeatureCheckboxes();
        updateModalTabs();
        renderSockets();
        if (modalTab === 'cards') {
            await refreshCardsView();
        } else if (modalTab === 'stamps') {
            await refreshStampsView();
        } else {
            renderSockets();
            renderModalContent(true);
        }
    });
}

classSelect.addEventListener('change', onClassChange);
level.addEventListener('change', onLevelChange);
genderSelect.addEventListener('change', onCharacterChange);

modalOverlay.addEventListener('click', e => {
    if (e.target === modalOverlay) {
        closeModal();
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modalOverlay.hidden) {
        closeModal();
    }
});

let searchRenderTimer = null;
searchInput.addEventListener('input', () => {
    if (searchRenderTimer) {
        clearTimeout(searchRenderTimer);
    }
    searchRenderTimer = setTimeout(() => {
        searchRenderTimer = null;
        if (!modalOverlay.hidden) {
            renderModalContent();
        }
    }, 120);
});

searchInput.addEventListener('focus', () => {
    searchStale = false;
});

searchInput.addEventListener('blur', () => {
    if (searchInput.value !== '') {
        searchStale = true;
    }
});

modalFeatures.addEventListener('change', event => {
    const input = event.target.closest('input[type="checkbox"][data-checkbox-id]');

    if (!input) {
        return;
    }

    const checkboxId = input.dataset.checkboxId;

    if (input.checked) {
        enabledFeatureCheckboxIds.add(checkboxId);
    } else {
        enabledFeatureCheckboxIds.delete(checkboxId);
    }

    saveFeatureCheckboxIds();

    if (!modalOverlay.hidden) {
        renderModalContent();
    }
});

if (stateSelect) {
    stateSelect.addEventListener('change', () => {
        currentStateId = normalizeCurrentStateId(stateSelect.value);

        saveCurrentStateId();

        if (!modalOverlay.hidden) {
            renderModalContent();
        }

        if (typeof scheduleEquipmentCalculationRefresh === 'function') {
            scheduleEquipmentCalculationRefresh(0);
        }
    });
}

if (stampColorSelect) {
    stampColorSelect.addEventListener('change', () => {
        if (modalOverlay.hidden || modalTab !== 'stamps') {
            return;
        }
        const colorId = Number(stampColorSelect.value || 0);
        if (!colorId) {
            return;
        }
        const state = getStampState();
        if (!state || state.locked) {
            return;
        }
        stampColorId = colorId;
        const saved = getSavedActiveItem();
        if (saved && state.variant) {
            const group = getStampGroupsForContext().find(g => g.id === Number(state.variant.stamp_id || 0));
            const variant = group ? findStampVariantByColor(group, colorId) : null;
            if (variant && Number(variant.id) !== Number(state.variant.id)) {
                markStampVariantFull(variant);
                saved.user_stamp = makeStampSnapshot(variant);
                saveEquipment();
            }
        }
        invalidateRender();
        renderSockets();
        renderStampsTab();
    });
}

upgradeSelect.addEventListener('change', () => {
    if (modalOverlay.hidden || activeSlot === null) {
        return;
    }

    const slotKey = String(activeSlot);
    const saved = equipment[slotKey];

    if (!saved) {
        return;
    }

    const currentItem = currentItems.find(item => Number(item.id) === Number(saved.id));
    const source = currentItem || saved;

    if (Number(source.can_upgrade || 0) !== 1) {
        return;
    }

    const max = getMaxUpgradeForGrade(source.grade);
    let value = parseInt(upgradeSelect.value, 10);

    if (!Number.isFinite(value) || value < 0) {
        value = 0;
    }

    if (value > max) {
        value = max;
    }

    modalUpgrade = value;
    saved.upgrade = value;

    saveEquipment();
    updateSlotMarkers();
    renderModalContent();

    requestAnimationFrame(() => {
        upgradeSelect.focus();
    });
});

document.addEventListener('keydown', event => {
    if (modalOverlay.hidden) {
        return;
    }

    if (event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    if (event.key.length !== 1) {
        return;
    }

    const target = event.target;

    if (
        event.key === ' ' &&
        target instanceof HTMLElement &&
        target.closest('input[type="checkbox"], input[type="radio"], select, button')
    ) {
        return;
    }

    if (isTextEntryTarget(target)) {
        return;
    }

    if (document.activeElement === searchInput) {
        return;
    }

    event.preventDefault();

    if (searchStale) {
        searchInput.value = '';
        searchStale = false;
        renderModalContent();
    }

    searchInput.focus();
    insertCharIntoSearch(event.key);
});

async function loadClasses() {
    const rows = fromBootstrap('classes') ?? await apiJson('classes');
    if (!Array.isArray(rows)) {
        throw new Error('Некорректный ответ classes.');
    }
    buildInfo(rows);
    buildSelect(rows);
}
async function init() {
    equipment = loadEquipment();
    enabledFeatureCheckboxIds = loadFeatureCheckboxIds();
    disableUpgradeControl();
    updateModalTabs();

    try {
        await loadBootstrap();
    } catch (e) {
        reportLoadError('справочники', e);
    }

    await Promise.all([
        loadSettings(),
        loadEnumLabels(),
        loadBaseStats(),
        loadSlots(),
        loadItemTypeTabs(),
        loadStamps(),
        (async () => {
            await loadTabs();
            await loadFeatures();
        })(),
        loadReferenceNames(),
        loadCardSets(),
        loadStates(),
        loadSlotRules(),
        loadUpgrades(),
        loadGrades(),
        loadClasses().catch(e => reportLoadError('классы', e)),
    ]);

    BOOTSTRAP_DATA = null;
    currentStateId = normalizeCurrentStateId(storageGet(CURRENT_STATE_STORAGE_KEY));
    renderGenderSelect();
    renderStateSelect();
    renderFeatureCheckboxes();
    renderSlots();
    initSlotIconErrors();
    initModalA11y();

    if (!restoreCharacterSelection()) {
        showDbError('Не удалось загрузить классы из БД. Запусти MySQL и обнови страницу.');
        return;
    }
    await refreshEquipmentCalculationData();
    await onCharacterChange();
}

init();