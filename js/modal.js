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

