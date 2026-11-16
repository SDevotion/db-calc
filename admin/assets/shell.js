(function () {
'use strict';

const NAV_SCROLL_KEY = 'kc_admin_nav_scroll';

/* ---------------- Сайдбар ---------------- */

function initSidebar() {
    const nav = document.getElementById('admin-nav');
    if (!nav) {
        return;
    }
    const saved = Number(sessionStorage.getItem(NAV_SCROLL_KEY) || 0);
    if (saved > 0) {
        nav.scrollTop = saved;
    }
    nav.addEventListener('scroll', () => {
        try {
            sessionStorage.setItem(NAV_SCROLL_KEY, String(nav.scrollTop));
        } catch (e) { /* noop */ }
    }, { passive: true });

    const active = nav.querySelector('.nav-link.is-active');
    if (active && !saved) {
        nav.scrollTop = Math.max(0, active.offsetTop - nav.clientHeight / 2 + active.offsetHeight / 2);
    }

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        if (resizeTimer) {
            window.clearTimeout(resizeTimer);
        }
        resizeTimer = window.setTimeout(layoutTables, 120);
    });
}

/* ---------------- Поиск по разделам ---------------- */

function initNavSearch() {
    const input = document.getElementById('nav-search');
    const nav = document.getElementById('admin-nav');
    if (!input || !nav) {
        return;
    }
    const links = Array.from(nav.querySelectorAll('[data-nav-link]'));
    const groups = Array.from(nav.querySelectorAll('[data-nav-group]'));
    const empty = nav.querySelector('[data-nav-empty]');

    function apply() {
        const q = input.value.trim().toLowerCase();
        let visible = 0;
        links.forEach(link => {
            const hit = !q || (link.dataset.navSearch || '').includes(q);
            link.hidden = !hit;
            if (hit) {
                visible++;
            }
        });
        groups.forEach(group => {
            group.hidden = !Array.from(group.querySelectorAll('[data-nav-link]')).some(l => !l.hidden);
        });
        if (empty) {
            empty.hidden = visible > 0;
        }
    }

    input.addEventListener('input', apply);
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            const first = links.find(link => !link.hidden);
            if (first) {
                event.preventDefault();
                window.location.href = first.href;
            }
        }
        if (event.key === 'Escape') {
            input.value = '';
            apply();
            input.blur();
        }
    });

    document.addEventListener('keydown', event => {
        if (document.body.classList.contains('has-dialog')) {
            return;
        }
        const target = event.target;
        const typing = target instanceof HTMLElement &&
            (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' ||
             target.tagName === 'SELECT' || target.isContentEditable);

        if ((event.key === '/' && !typing) ||
            ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k')) {
            event.preventDefault();
            input.focus();
            input.select();
        }
    });
}

/* ---------------- Тосты ---------------- */

function initToasts() {
    const stack = document.getElementById('toast-stack');
    if (!stack) {
        return;
    }
    const LIFE = 6000;

    function dismiss(toast) {
        if (toast.dataset.leaving === '1') {
            return;
        }
        toast.dataset.leaving = '1';
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 220);
    }

    Array.from(stack.querySelectorAll('[data-toast]')).forEach(toast => {
        const type = toast.dataset.toastType || 'info';
        const sticky = type === 'error' || type === 'warning';
        toast.style.setProperty('--t-life', LIFE + 'ms');

        let timer = sticky ? null : window.setTimeout(() => dismiss(toast), LIFE);

        toast.addEventListener('mouseenter', () => {
            toast.classList.add('is-paused');
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }
        });
        toast.addEventListener('mouseleave', () => {
            toast.classList.remove('is-paused');
            if (!sticky && !timer && toast.dataset.leaving !== '1') {
                timer = window.setTimeout(() => dismiss(toast), 2600);
            }
        });
        toast.querySelector('[data-toast-close]')?.addEventListener('click', () => {
            if (timer) {
                window.clearTimeout(timer);
            }
            dismiss(toast);
        });
    });
}

/* ---------------- Таблицы без прокрутки ---------------- */

function labelTables() {
    document.querySelectorAll('.admin-main table').forEach(table => {
        if (table.dataset.labelled === '1') {
            return;
        }
        table.dataset.labelled = '1';
        const labels = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
        table.dataset.cols = String(labels.length);
        if (!labels.length) {
            return;
        }
        table.querySelectorAll('tbody tr').forEach(row => {
            Array.from(row.children).forEach((cell, index) => {
                if (cell.hasAttribute('colspan')) {
                    return;
                }
                if (!cell.hasAttribute('data-label') && labels[index]) {
                    cell.setAttribute('data-label', labels[index]);
                }
            });
        });
    });
}

function layoutTables() {
    labelTables();
    const width = window.innerWidth;
    document.querySelectorAll('.admin-main table').forEach(table => {
        const cols = Number(table.dataset.cols || 0);
        const editable = Boolean(table.closest('form'));
        const stacked = editable ? width < 900 : (width < 1000 || cols > 8);
        table.classList.toggle('is-stacked', stacked);
    });
}

/* ---------------- Быстрый фильтр таблиц ---------------- */

function initTableFilters() {
    document.querySelectorAll('[data-table-filter]').forEach(input => {
        const table = document.getElementById(input.dataset.tableFilter);
        const tbody = table ? table.querySelector('tbody') : null;
        if (!tbody) {
            return;
        }
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => {
            const first = row.firstElementChild;
            return !(first && first.hasAttribute('colspan'));
        });
        if (!rows.length) {
            return;
        }

        const scope = table.closest('.card') || document;
        const counter = scope.querySelector('[data-filter-count]');
        const total = rows.length;
        const colCount = table.querySelectorAll('thead th').length || 1;

        const emptyRow = document.createElement('tr');
        emptyRow.hidden = true;
        const emptyCell = document.createElement('td');
        emptyCell.colSpan = colCount;
        emptyCell.textContent = 'По фильтру ничего не найдено.';
        emptyRow.appendChild(emptyCell);
        tbody.appendChild(emptyRow);

        const haystack = rows.map(row => row.textContent.toLowerCase().replace(/\s+/g, ' '));

        function apply() {
            const q = input.value.trim().toLowerCase();
            let visible = 0;
            rows.forEach((row, index) => {
                const hit = !q || haystack[index].includes(q);
                row.hidden = !hit;
                if (hit) {
                    visible++;
                }
            });
            emptyRow.hidden = visible > 0;
            if (counter) {
                counter.textContent = q
                    ? ('Найдено: ' + visible + ' из ' + total)
                    : ('Записей: ' + total);
                counter.classList.toggle('badge-ok', Boolean(q) && visible > 0);
                counter.classList.toggle('badge-warn', Boolean(q) && visible === 0);
            }
        }

        input.addEventListener('input', apply);
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                input.value = '';
                apply();
            }
        });
        apply();
    });
}

/* ---------------- Диалог подтверждения ---------------- */

function initConfirm() {
    let scrim = null;
    let resolveCurrent = null;
    let lastFocused = null;

    function build() {
        scrim = document.createElement('div');
        scrim.className = 'confirm-scrim';
        scrim.hidden = true;
        scrim.innerHTML =
            '<div class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text">' +
            '<div class="confirm-head">' +
            '<span class="confirm-ico" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.8 21 19.6H3Z"/><path d="M12 9.8v4.2"/><path d="M12 17h.01"/></svg>' +
            '</span>' +
            '<span class="confirm-title" id="confirm-title">Подтвердите действие</span>' +
            '</div>' +
            '<p class="confirm-text" id="confirm-text"></p>' +
            '<div class="confirm-actions">' +
            '<button type="button" class="btn" data-confirm-cancel>Отмена</button>' +
            '<button type="button" class="btn btn-danger" data-confirm-ok>Подтвердить</button>' +
            '</div>' +
            '</div>';
        document.body.appendChild(scrim);

        scrim.addEventListener('click', event => {
            if (event.target === scrim) {
                close(false);
            }
        });
        scrim.querySelector('[data-confirm-cancel]').addEventListener('click', () => close(false));
        scrim.querySelector('[data-confirm-ok]').addEventListener('click', () => close(true));

        scrim.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = Array.from(scrim.querySelectorAll('button'));
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    }

    function close(result) {
        if (!scrim || scrim.hidden) {
            return;
        }
        scrim.hidden = true;
        document.body.classList.remove('has-dialog');
        const resolve = resolveCurrent;
        resolveCurrent = null;
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
        lastFocused = null;
        resolve?.(result);
    }

    window.kcConfirm = function (message) {
        if (!scrim) {
            build();
        }
        if (resolveCurrent) {
            close(false);
        }
        return new Promise(resolve => {
            resolveCurrent = resolve;
            lastFocused = document.activeElement;
            scrim.querySelector('#confirm-text').textContent = String(message || '');
            scrim.hidden = false;
            document.body.classList.add('has-dialog');
            window.setTimeout(() => scrim.querySelector('[data-confirm-cancel]').focus(), 40);
        });
    };
}

/* ---------------- Плавающая панель сохранения ---------------- */

function initFormDock() {
    const wrap = document.querySelector('.admin-main-wrap');
    const scroller = document.getElementById('admin-main');
    if (!wrap || !scroller || !('IntersectionObserver' in window)) {
        return;
    }
    const forms = Array.from(scroller.querySelectorAll('form[method="post"]'))
        .filter(form => form.querySelector('.form-actions'));
    if (!forms.length) {
        return;
    }

    const form = forms[0];
    const actions = form.querySelector('.form-actions');
    const submit = actions.querySelector('button[type="submit"]');
    if (!submit) {
        return;
    }

    const dock = document.createElement('div');
    dock.className = 'form-dock';
    dock.setAttribute('data-form-dock', '');
    dock.innerHTML =
        '<span class="dock-state" data-dock-state>Быстрое сохранение</span>' +
        '<button type="button" class="btn btn-primary" data-dock-submit></button>';
    const dockSubmit = dock.querySelector('[data-dock-submit]');
    dockSubmit.textContent = submit.textContent.trim() || 'Сохранить';
    wrap.appendChild(dock);

    dockSubmit.addEventListener('click', () => submit.click());

    let visible = false;
    function setVisible(next) {
        if (visible === next) {
            return;
        }
        visible = next;
        dock.classList.toggle('is-visible', next);
        document.body.classList.toggle('has-dock', next);
    }

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => setVisible(!entry.isIntersecting));
    }, { root: scroller, threshold: 0.1 });
    observer.observe(actions);

    window.kcDockState = function (dirty) {
        dock.classList.toggle('is-dirty', Boolean(dirty));
        dock.querySelector('[data-dock-state]').textContent = dirty
            ? 'Есть несохранённые изменения'
            : 'Быстрое сохранение';
    };
    window.kcDockState(false);
}

/* ---------------- Защита от потери изменений ---------------- */

function initDirtyGuard() {
    const forms = Array.from(document.querySelectorAll('.admin-main form[method="post"]'))
        .filter(form => form.querySelector('.form-actions'));
    if (!forms.length) {
        return;
    }
    const tracked = new Set(forms);
    let dirty = false;

    function mark(next) {
        if (dirty === next) {
            return;
        }
        dirty = next;
        window.kcDockState?.(dirty);
    }

    document.addEventListener('input', event => {
        const form = event.target instanceof Element ? event.target.closest('form') : null;
        if (form && tracked.has(form)) {
            mark(true);
        }
    });
    document.addEventListener('change', event => {
        const form = event.target instanceof Element ? event.target.closest('form') : null;
        if (form && tracked.has(form)) {
            mark(true);
        }
    });
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) {
            return;
        }
        if (event.target.closest('.js-add-bonus, .js-remove-bonus, .js-add-override, .js-remove-override, .js-add-condition, .js-remove-condition, .js-add-stat-source, .js-remove-stat-source, [data-check-all], [data-uncheck-all], [data-select-base-all], [data-clear-classes]')) {
            mark(true);
        }
    });
    document.addEventListener('submit', event => {
        const form = event.target instanceof Element ? event.target.closest('form') : null;
        if (form && tracked.has(form)) {
            mark(false);
        }
    });

    window.kcClearDirty = function () {
        mark(false);
    };

    window.addEventListener('beforeunload', event => {
        if (!dirty) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
}

/* ---------------- Мелочи ---------------- */

function initMisc() {
    document.querySelectorAll('a[target="_blank"]').forEach(link => {
        const rel = (link.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
        if (!rel.includes('noopener')) {
            rel.push('noopener');
            link.setAttribute('rel', rel.join(' '));
        }
    });

    document.querySelectorAll('.admin-main form').forEach(form => {
        form.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                const submit = form.querySelector('button[type="submit"], .btn-primary');
                if (submit) {
                    event.preventDefault();
                    submit.click();
                }
            }
        });
    });

    const filter = document.getElementById('crud-filter');
    if (filter) {
        document.addEventListener('keydown', event => {
            if (document.body.classList.contains('has-dialog')) {
                return;
            }
            const target = event.target;
            const typing = target instanceof HTMLElement &&
                (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' ||
                 target.tagName === 'SELECT' || target.isContentEditable);
            if (event.key.toLowerCase() === 'f' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                filter.focus();
                filter.select();
            }
        });
    }
}

function boot() {
    initSidebar();
    initNavSearch();
    initToasts();
    layoutTables();
    initTableFilters();
    initConfirm();
    initFormDock();
    initDirtyGuard();
    initMisc();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
})();