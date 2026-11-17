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

        // Готовая карта счётчиков из расчёта; иначе — кэш на рендер внутри функции.
        const count = context.identicalCardCounts instanceof Map ?
            Number(context.identicalCardCounts.get(Number(context.cardId || 0)) || 0) :
            (context.cardId && typeof window.getEquippedIdenticalCardCount === 'function' ?
                Number(window.getEquippedIdenticalCardCount(context.cardId) || 0) :
                0);

        if (multiplier !== null && count >= required) {
            value = value * multiplier;
            factor = multiplier;
        } else {
            factor = 1;
            addHint(reasonMultiplyIdenticalCards(bonus));
        }
    } else if (scalingType === 'per_stat') {
        const totals = options.totals instanceof Map ?
            options.totals :
            (context.totals instanceof Map ?
                context.totals :
                (window.CALC_STATE && window.CALC_STATE.ready ? window.CALC_STATE.totals : null));

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
        const totals = options.totals instanceof Map ?
            options.totals :
            (context.totals instanceof Map ?
                context.totals :
                (window.CALC_STATE && window.CALC_STATE.ready ? window.CALC_STATE.totals : null));

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
    // totals передаём через options — без копии контекста на каждый бонус.
    return resolveBonus(bonus, context, {
        mode: 'calc',
        totals
    });
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

