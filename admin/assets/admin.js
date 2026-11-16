document.addEventListener('DOMContentLoaded', initAdminDynamicControls);
document.addEventListener('click', handleAdminClick);
document.addEventListener('change', handleAdminChange);
document.addEventListener('submit', handleAdminSubmit);

function initAdminDynamicControls() {
document.querySelectorAll('select[name="bonus_stat[]"]').forEach(syncStatTypes);
syncSingleStatType();
syncSlotRuleTypeFieldIfNeeded();
syncBaseStatFields();
syncCardSetMainToggle();
syncBonusScalingFields();
syncOverrideValueMaxFields();
syncConditionScalingFields();
syncSlotCounter();
syncClassMasters();
syncGradeUpgradeLock();
syncStampCoefFields();
syncStampCoefHint();
}

/* ---------------- Клики ---------------- */

function handleAdminClick(event) {
if (!(event.target instanceof Element)) {
    return;
}

const addButton = event.target.closest('.js-add-bonus');
if (addButton) {
    const template = document.getElementById('bonus-row-template');
    const tbody = document.getElementById('bonus-rows');
    if (!template || !tbody) {
        return;
    }
    const row = template.content.firstElementChild.cloneNode(true);
    tbody.appendChild(row);
    const statSelect = row.querySelector('select[name="bonus_stat[]"]');
    if (statSelect) {
        syncStatTypes(statSelect);
    }
    revealRow(row);
    statSelect?.focus();
    return;
}

const removeButton = event.target.closest('.js-remove-bonus');
if (removeButton) {
    const row = removeButton.closest('tr');
    const tbody = document.getElementById('bonus-rows');
    if (!row || !tbody || !tbody.contains(row)) {
        return;
    }
    if (tbody.querySelectorAll('tr').length > 1) {
        row.remove();
    } else {
        resetBonusRow(row);
    }
    return;
}

const addOverrideButton = event.target.closest('.js-add-override');
if (addOverrideButton) {
    const row = appendTemplateRow('bonus-override-template', 'bonus-overrides');
    syncOverrideValueMaxFields();
    revealRow(row);
    row?.querySelector('select, input')?.focus();
    return;
}

const removeOverrideButton = event.target.closest('.js-remove-override');
if (removeOverrideButton) {
    removeOverrideButton.closest('.dynamic-row')?.remove();
    return;
}

const addConditionButton = event.target.closest('.js-add-condition');
if (addConditionButton) {
    const row = appendTemplateRow('item-type-condition-template', 'item-type-conditions');
    revealRow(row);
    row?.querySelector('select, input')?.focus();
    return;
}

const removeConditionButton = event.target.closest('.js-remove-condition');
if (removeConditionButton) {
    removeConditionButton.closest('.dynamic-row')?.remove();
    return;
}

const addStatSourceButton = event.target.closest('.js-add-stat-source');
if (addStatSourceButton) {
    const row = appendTemplateRow('bonus-stat-source-template', 'bonus-stat-sources');
    revealRow(row);
    row?.querySelector('select, input')?.focus();
    return;
}

const removeStatSourceButton = event.target.closest('.js-remove-stat-source');
if (removeStatSourceButton) {
    removeStatSourceButton.closest('.dynamic-row')?.remove();
    return;
}

const checkAll = event.target.closest('[data-check-all]');
if (checkAll) {
    setCheckboxes(checkAll.dataset.checkAll, true);
    syncSlotCounter();
    return;
}

const uncheckAll = event.target.closest('[data-uncheck-all]');
if (uncheckAll) {
    setCheckboxes(uncheckAll.dataset.uncheckAll, false);
    syncSlotCounter();
    return;
}

if (event.target.closest('[data-select-base-all]')) {
    document.querySelectorAll('.js-base-class').forEach(checkbox => {
        checkbox.checked = true;
    });
    syncClassMasters();
    return;
}

if (event.target.closest('[data-clear-classes]')) {
    document.querySelectorAll('input[name="classes[]"], .js-group-master').forEach(checkbox => {
        checkbox.checked = false;
    });
    syncClassMasters();
}
}

function appendTemplateRow(templateId, containerId) {
const template = document.getElementById(templateId);
const container = document.getElementById(containerId);
if (!template || !container) {
    return null;
}
const row = template.content.firstElementChild.cloneNode(true);
container.appendChild(row);
return row;
}

function revealRow(row) {
if (!row) {
    return;
}
requestAnimationFrame(() => {
    row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});
}

function setCheckboxes(selector, checked) {
const container = selector ? document.querySelector(selector) : null;
if (!container) {
    return;
}
container.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
    checkbox.checked = checked;
});
}

function resetBonusRow(row) {
const hiddenId = row.querySelector('input[name="bonus_id[]"]');
const statSelect = row.querySelector('select[name="bonus_stat[]"]');
const valueInput = row.querySelector('input[name="bonus_value[]"]');
const featureSelect = row.querySelector('select[name="bonus_feature[]"]');
const textNoteSelect = row.querySelector('select[name="bonus_text_note[]"]');
const stateSelect = row.querySelector('select[name="bonus_state[]"]');
if (hiddenId) { hiddenId.value = '0'; }
if (statSelect) { statSelect.value = '0'; }
if (valueInput) { valueInput.value = ''; }
if (featureSelect) { featureSelect.value = ''; }
if (textNoteSelect) { textNoteSelect.value = ''; }
if (stateSelect) { stateSelect.value = ''; }
if (statSelect) { syncStatTypes(statSelect); }
}

/* ---------------- Изменения ---------------- */

function handleAdminChange(event) {
if (!(event.target instanceof Element)) {
    return;
}

if (event.target.closest('#stat_id')) {
    syncSingleStatType();
    syncStampCoefHint();
    return;
}
if (event.target.closest('#value_type')) {
    syncStampCoefHint();
    return;
}
if (event.target.closest('#supports_flat, #supports_percent')) {
    syncStampCoefFields();
    return;
}

const statSelect = event.target.closest('select[name="bonus_stat[]"]');
if (statSelect) {
    syncStatTypes(statSelect);
    return;
}

const groupMaster = event.target.closest('.js-group-master');
if (groupMaster) {
    const fieldset = groupMaster.closest('fieldset');
    if (fieldset) {
        fieldset.querySelectorAll('input[name="classes[]"]').forEach(checkbox => {
            checkbox.checked = groupMaster.checked;
        });
    }
    return;
}

if (event.target.closest('input[name="classes[]"]')) {
    syncClassMasters();
    return;
}

if (event.target.closest('#slot-checkboxes')) {
    syncSlotCounter();
    return;
}

if (event.target.closest('#rule_restriction')) {
    syncSlotRuleTypeFieldIfNeeded();
    return;
}

if (event.target.closest('#is_base')) {
    syncBaseStatFields();
    return;
}

if (event.target.closest('#grade')) {
    syncGradeUpgradeLock();
    return;
}

if (event.target.closest('#set_id')) {
    syncCardSetMainToggle();
    return;
}

if (event.target.closest('#bonus-scaling-type')) {
    syncBonusScalingFields();
    syncOverrideValueMaxFields();
    return;
}

if (event.target.closest('#scaling_type')) {
    syncConditionScalingFields();
}
}

/* ---------------- Подтверждение удаления ---------------- */

async function handleAdminSubmit(event) {
const form = event.target.closest('form.js-confirm');
if (!form || form.dataset.confirmed === '1') {
    return;
}
event.preventDefault();
if (form.dataset.busy === '1') {
    return;
}
form.dataset.busy = '1';

const message = form.dataset.confirm || 'Подтвердите действие.';
let approved = false;
try {
    approved = window.kcConfirm
        ? await window.kcConfirm(message)
        : window.confirm(message);
} finally {
    form.dataset.busy = '';
}

if (approved) {
    form.dataset.confirmed = '1';
    window.kcClearDirty?.();
    form.submit();
}
}

/* ---------------- Типы значений ---------------- */

function valueTypeLabelMap(typeSelect) {
if (!typeSelect.dataset.labels) {
    const labels = {};
    Array.from(typeSelect.options).forEach(option => {
        labels[option.value] = option.textContent;
    });
    typeSelect.dataset.labels = JSON.stringify(labels);
}
try {
    return JSON.parse(typeSelect.dataset.labels);
} catch (e) {
    return {};
}
}

function fillValueTypeSelect(typeSelect, supportsFlat, supportsPercent, currentValue) {
const labels = valueTypeLabelMap(typeSelect);
typeSelect.innerHTML = '';
if (supportsFlat) {
    typeSelect.appendChild(new Option(labels.flat || 'flat', 'flat'));
}
if (supportsPercent) {
    typeSelect.appendChild(new Option(labels.percent || 'percent', 'percent'));
}
if (!typeSelect.options.length) {
    typeSelect.appendChild(new Option(labels.flat || 'flat', 'flat'));
}
const values = Array.from(typeSelect.options).map(option => option.value);
typeSelect.value = values.includes(currentValue) ? currentValue : values[0];
}

function syncStatTypes(statSelect) {
const row = statSelect.closest('tr');
const typeSelect = row ? row.querySelector('select[name="bonus_type[]"]') : null;
if (!typeSelect) {
    return;
}
const option = statSelect.selectedOptions[0];
fillValueTypeSelect(
    typeSelect,
    option?.dataset.flat === '1',
    option?.dataset.percent === '1',
    typeSelect.value
);
}

function syncSingleStatType() {
const statSelect = document.getElementById('stat_id');
const typeSelect = document.getElementById('value_type');
if (!statSelect || !typeSelect) {
    return;
}
const option = statSelect.selectedOptions[0];
const supportsFlat = option?.dataset.flat === '1';
const supportsPercent = option?.dataset.percent === '1';
if (!supportsFlat && !supportsPercent) {
    return;
}
fillValueTypeSelect(typeSelect, supportsFlat, supportsPercent, typeSelect.value);
}

/* ---------------- Справочники ---------------- */

function syncSlotRuleTypeFieldIfNeeded() {
const restrictionSelect = document.getElementById('rule_restriction');
const typeSelect = document.getElementById('rule_type');
if (!restrictionSelect || !typeSelect) {
    return;
}
const isVisibility = restrictionSelect.value === 'show' || restrictionSelect.value === 'hide';
typeSelect.disabled = isVisibility;
typeSelect.required = !isVisibility;
if (isVisibility) {
    typeSelect.value = '';
}
const wrap = typeSelect.closest('.field');
if (wrap) {
    wrap.hidden = isVisibility;
}
}

function syncBaseStatFields() {
const flag = document.getElementById('is_base');
if (!flag) {
    return;
}
['base_field', 'base_format'].forEach(id => {
    const input = document.getElementById(id);
    if (!input) {
        return;
    }
    const wrap = input.closest('.field');
    const enabled = flag.checked;
    input.disabled = !enabled;
    input.required = enabled;
    if (!enabled) {
        input.value = '';
    }
    if (wrap) {
        wrap.hidden = !enabled;
    }
});
}

/* Показываем только те пары коэффициентов, которые применимы к характеристике */
function syncStampCoefFields() {
    const flat = document.getElementById('supports_flat');
    const percent = document.getElementById('supports_percent');
    if (!flat || !percent) {
        return;
    }
    [
        ['stamp_flat_min_coef', flat.checked],
        ['stamp_flat_max_coef', flat.checked],
        ['stamp_percent_min_coef', percent.checked],
        ['stamp_percent_max_coef', percent.checked],
    ].forEach(([id, visible]) => {
        const wrap = document.getElementById(id)?.closest('.field');
        if (wrap) {
            wrap.hidden = !visible;
        }
    });
}
/* Подсказка: какая пара коэффициентов будет применена к вводимому максимуму */
function syncStampCoefHint() {
    const hint = document.getElementById('stamp-coef-hint');
    const statSelect = document.getElementById('stat_id');
    const typeSelect = document.getElementById('value_type');
    if (!hint || !statSelect || !typeSelect) {
        return;
    }
    const option = statSelect.selectedOptions[0];
    if (!option || !option.dataset.flatMin) {
        hint.textContent = '';
        return;
    }
    const isPercent = typeSelect.value === 'percent';
    const min = isPercent ? option.dataset.percentMin : option.dataset.flatMin;
    const max = isPercent ? option.dataset.percentMax : option.dataset.flatMax;
    hint.textContent = 'Коэффициенты (' + (isPercent ? '%' : 'flat') + '): ' + min + ' → ' + max;
}

function syncCardSetMainToggle() {
const setSelect = document.getElementById('set_id');
const mainCheckbox = document.getElementById('is_set_main');
if (!setSelect || !mainCheckbox) {
    return;
}
const hasSet = setSelect.value !== '';
mainCheckbox.disabled = !hasSet;
if (!hasSet) {
    mainCheckbox.checked = false;
}
}

/* ---------------- Счётчики и мастера ---------------- */

function syncSlotCounter() {
const counter = document.querySelector('[data-slot-count]');
const container = document.getElementById('slot-checkboxes');
if (!counter || !container) {
    return;
}
const total = container.querySelectorAll('input[type="checkbox"]').length;
const checked = container.querySelectorAll('input[type="checkbox"]:checked').length;
counter.textContent = 'Выбрано: ' + checked + ' из ' + total;
counter.classList.toggle('badge-ok', checked > 0);
counter.classList.toggle('badge-danger', checked === 0);
}

function syncGradeUpgradeLock() {
const gradeSelect = document.getElementById('grade');
const upgradeBox = document.querySelector('input[name="can_upgrade"]');
if (!gradeSelect || !upgradeBox) {
    return;
}
const noGrade = gradeSelect.value === '';
upgradeBox.disabled = noGrade;
if (noGrade) {
    upgradeBox.checked = false;
}
}
function syncClassMasters() {
document.querySelectorAll('.class-group').forEach(fieldset => {
    const master = fieldset.querySelector('.js-group-master');
    const boxes = Array.from(fieldset.querySelectorAll('input[name="classes[]"]'));
    if (!master || !boxes.length) {
        return;
    }
    const checked = boxes.filter(box => box.checked).length;
    master.checked = checked === boxes.length;
    master.indeterminate = checked > 0 && checked < boxes.length;
});
}

/* ---------------- Масштабирование ---------------- */

function syncBonusScalingFields() {
const scalingSelect = document.getElementById('bonus-scaling-type');
if (!scalingSelect) {
    return;
}
const type = scalingSelect.value;
const showValueMax = type === 'range';
const showLevelStep = ['per_level', 'per_every_n_levels', 'per_level_above'].includes(type);
const showLevelThreshold = ['per_level_above', 'once_above'].includes(type);
const showStatSingle = type === 'stat_threshold';
const showStatMultiple = type === 'per_stat';
const showMultiply = type === 'multiply_by_identical_cards';

setFieldVisibility('bonus-value-max-wrap', 'value_max', showValueMax, showValueMax, '');
setFieldVisibility('bonus-step-wrap', 'scaling_step', showLevelStep, showLevelStep, '1');
setFieldVisibility('bonus-threshold-wrap', 'scaling_threshold', showLevelThreshold, showLevelThreshold, '0');
setFieldVisibility('bonus-stat-source-wrap', 'stat_source_id', showStatSingle, showStatSingle, '');
setFieldVisibility('bonus-stat-threshold-wrap', 'stat_threshold', showStatSingle, showStatSingle, '');
setFieldVisibility('bonus-stat-value-wrap', 'stat_value_true', showStatSingle, showStatSingle, '');
setFieldVisibility('bonus-stat-mode-wrap', 'stat_condition_mode', showStatMultiple, showStatMultiple, 'all');
setFieldVisibility('bonus-required-count-wrap', 'scaling_required_count', showMultiply, showMultiply, '1');
setFieldVisibility('bonus-multiplier-wrap', 'scaling_multiplier', showMultiply, showMultiply, '');

const sourcesWrap = document.getElementById('bonus-stat-sources-wrap');
if (sourcesWrap) {
    sourcesWrap.hidden = !showStatMultiple;
    sourcesWrap.querySelectorAll('select, input, button').forEach(control => {
        control.disabled = !showStatMultiple;
    });
}
}

function syncOverrideValueMaxFields() {
const scalingSelect = document.getElementById('bonus-scaling-type');
const isRange = scalingSelect?.value === 'range';
document.querySelectorAll('#bonus-overrides .dynamic-row').forEach(row => {
    row.classList.toggle('is-compact', !isRange);
    const input = row.querySelector('input[name="override_value_max[]"]');
    if (!input) {
        return;
    }
    input.disabled = !isRange;
    input.hidden = !isRange;
    if (!isRange) {
        input.value = '';
    }
});
}

function syncConditionScalingFields() {
const scalingSelect = document.getElementById('scaling_type');
if (!scalingSelect) {
    return;
}
const type = scalingSelect.value;
const showLevelStep = ['per_level', 'per_every_n_levels', 'per_level_above'].includes(type);
const showLevelThreshold = ['per_level_above', 'once_above'].includes(type);
const showStat = ['per_stat', 'stat_threshold'].includes(type);

setFieldVisibility('cond-step-wrap', 'scaling_step', showLevelStep, showLevelStep, '1');
setFieldVisibility('cond-threshold-wrap', 'scaling_threshold', showLevelThreshold, showLevelThreshold, '0');
setFieldVisibility('cond-stat-wrap', 'stat_source_id', showStat, showStat, '');
setFieldVisibility('cond-stat-step-wrap', 'stat_step', type === 'per_stat', type === 'per_stat', '');
setFieldVisibility('cond-stat-threshold-wrap', 'stat_threshold', type === 'stat_threshold', type === 'stat_threshold', '');
setFieldVisibility('cond-stat-value-wrap', 'stat_value_true', type === 'stat_threshold', type === 'stat_threshold', '');
}

function setFieldVisibility(wrapId, inputId, visible, required, defaultValue) {
const wrap = document.getElementById(wrapId);
const input = document.getElementById(inputId);
if (wrap) {
    wrap.hidden = !visible;
}
if (!input) {
    return;
}
input.required = Boolean(required);
input.disabled = !visible;
if (!visible) {
    input.value = defaultValue;
}
}