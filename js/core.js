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

