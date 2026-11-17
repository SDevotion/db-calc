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