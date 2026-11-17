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

function searchMatchesTerms(text, statsOrGetter, parsed) {
    if (!parsed.terms.length) {
        return true;
    }

    if (!parsed.hasCondition && parsed.raw.trim().length < 3) {
        return true;
    }

    const lowerText = String(text || '').toLowerCase();

    // Сначала дешёвые текстовые условия: если текст не совпал,
    // числовые статы (резолв всех бонусов) считать не нужно.
    for (const term of parsed.terms) {
        if (term.type === 'text' && !lowerText.includes(term.text)) {
            return false;
        }
    }

    if (!parsed.hasCondition) {
        return true;
    }

    // Ленивый расчёт — только для элементов, прошедших текстовый фильтр.
    // Готовый массив тоже принимаем: обратная совместимость вызывающих.
    const stats = typeof statsOrGetter === 'function' ? statsOrGetter() : statsOrGetter;

    if (!Array.isArray(stats)) {
        return false;
    }

    return parsed.terms.every(term =>
        term.type === 'text' || stats.some(stat => searchStatMatchesCondition(stat, term))
    );
}

/* --- Мемоизация поискового текста --- */
// Ключ — сам объект сущности: после перезагрузки данных приходит новый
// объект и текст пересчитывается; старые записи уходят вместе с объектами.
const SEARCH_TEXT_CACHE = new WeakMap();

function memoizedSearchText(entity, upgradeLevel, compute) {
    if (!entity || typeof entity !== 'object') {
        return compute();
    }
    const cached = SEARCH_TEXT_CACHE.get(entity);
    if (cached && cached.upgrade === upgradeLevel) {
        return cached.text;
    }
    const text = compute();
    SEARCH_TEXT_CACHE.set(entity, { upgrade: upgradeLevel, text });
    return text;
}