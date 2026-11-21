// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Dashboard shell behaviour: collapsible menu groups (saved per user), mobile drawer,
 * filter panel, custom period dates, Ctrl+K, print, confirmations and sortable tables.
 *
 * Written as a plain AMD module (no ES module syntax), so it runs without a Grunt build.
 *
 * @module     local_ceduanalytics/shell
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core_user/repository'], function(UserRepository) {
    'use strict';

    const PREF = 'local_ceduanalytics_menu';

    /**
     * Save the ids of the open menu groups.
     *
     * @param {HTMLElement} app
     */
    const saveCollapsed = (app) => {
        const open = Array.from(app.querySelectorAll('.cea-navgroup.is-expanded'))
            .map((g) => g.dataset.group);
        UserRepository.setUserPreference(PREF, JSON.stringify({open: open})).catch(() => null);
    };

    /**
     * Sort a table by a column.
     *
     * @param {HTMLTableElement} table
     * @param {number} index
     * @param {HTMLElement} th
     */
    const sortTable = (table, index, th) => {
        const asc = th.getAttribute('aria-sort') !== 'ascending';
        table.querySelectorAll('th').forEach((h) => h.setAttribute('aria-sort', 'none'));
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        const numeric = th.classList.contains('cea-num');
        const value = (row) => {
            const text = (row.children[index] ? row.children[index].textContent : '').trim();
            if (numeric) {
                const n = parseFloat(text.replace(/\./g, '').replace(',', '.'));
                return isNaN(n) ? -Infinity : n;
            }
            return text.toLocaleLowerCase();
        };
        const body = table.tBodies[0];
        const rows = Array.from(body.rows);
        rows.sort((a, b) => {
            const va = value(a);
            const vb = value(b);
            const cmp = numeric ? va - vb : String(va).localeCompare(String(vb), undefined, {numeric: true});
            return asc ? cmp : -cmp;
        });
        rows.forEach((r) => body.appendChild(r));
    };

    /**
     * Links that leave the dashboard (Moodle pages, external sites) open in a new tab, so the dashboard stays open.
     * Dashboard pages, exports, in-page anchors and the "Back to Moodle" button keep the current tab.
     *
     * @param {HTMLElement} app
     */
    const externalLinksInNewTab = (app) => {
        const note = app.dataset.newtab || '';
        app.querySelectorAll('a[href]').forEach((a) => {
            const href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || a.closest('[data-region="cea-back"]')) {
                return;
            }
            let url;
            try {
                url = new URL(href, window.location.href);
            } catch (e) {
                return;
            }
            if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                return;
            }
            const inside = url.origin === window.location.origin && url.pathname.indexOf('/local/ceduanalytics/') !== -1;
            if (inside || a.target) {
                return;
            }
            a.target = '_blank';
            a.rel = 'noopener';
            if (note && !a.querySelector('.cea-newtab')) {
                const sr = document.createElement('span');
                sr.className = 'sr-only cea-newtab';
                sr.textContent = ' (' + note + ')';
                a.appendChild(sr);
            }
        });
    };

    return {
        /**
         * Initialise.
         */
        init: function() {
            const app = document.getElementById('cea-app');
            if (!app || app.dataset.ceaInit) {
                return;
            }
            app.dataset.ceaInit = '1';
            externalLinksInNewTab(app);

            // The Filters button is a link to the panel, which CSS shows while it is the URL target (a click before
            // this script loaded). From here on the script toggles it, so the target is turned into the open state.
            if (window.location.hash === '#cea-filterpanel') {
                const panel = document.getElementById('cea-filterpanel');
                const button = app.querySelector('[data-action="cea-filters"]');
                if (panel) {
                    panel.hidden = false;
                }
                if (button) {
                    button.setAttribute('aria-expanded', 'true');
                }
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }

            const openDrawer = () => app.classList.add('is-open');
            const closeDrawer = () => app.classList.remove('is-open');

            app.addEventListener('click', (e) => {
                const target = e.target.closest('[data-action]');
                if (!target || !app.contains(target)) {
                    return;
                }
                const action = target.dataset.action;
                if (action === 'cea-group') {
                    const group = target.closest('.cea-navgroup');
                    const list = group.querySelector('.cea-navlist');
                    const expanded = !group.classList.contains('is-expanded');
                    group.classList.toggle('is-expanded', expanded);
                    target.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    list.hidden = !expanded;
                    saveCollapsed(app);
                } else if (action === 'cea-open') {
                    openDrawer();
                } else if (action === 'cea-close') {
                    closeDrawer();
                } else if (action === 'cea-filters') {
                    e.preventDefault();
                    const panel = document.getElementById('cea-filterpanel');
                    if (panel) {
                        panel.hidden = !panel.hidden;
                        target.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                        if (!panel.hidden) {
                            const first = panel.querySelector('select, input');
                            if (first) {
                                first.focus();
                            }
                        }
                    }
                } else if (action === 'cea-print') {
                    window.print();
                } else if (action === 'cea-sort') {
                    const th = target.closest('th');
                    sortTable(th.closest('table'), Array.from(th.parentNode.children).indexOf(th), th);
                }
            });

            // Confirmation for buttons with data-confirm.
            app.addEventListener('submit', (e) => {
                const button = e.submitter || e.target.querySelector('[data-confirm]');
                if (button && button.dataset && button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
                    e.preventDefault();
                }
            });

            // Custom period: show the date fields.
            const period = app.querySelector('[data-region="cea-period"]');
            const dates = app.querySelector('[data-region="cea-dates"]');
            if (period && dates) {
                period.addEventListener('change', () => {
                    dates.hidden = period.value !== 'custom';
                });
            }

            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    const search = document.getElementById('cea-search-input');
                    if (search) {
                        e.preventDefault();
                        search.focus();
                    }
                } else if (e.key === 'Escape') {
                    closeDrawer();
                    app.querySelectorAll('details.cea-dropdown[open]').forEach((d) => d.removeAttribute('open'));
                }
            });

            // Quick text filter over a table.
            app.querySelectorAll('[data-region="cea-tablefilter"]').forEach((input) => {
                const table = document.getElementById(input.dataset.target);
                if (!table) {
                    return;
                }
                input.addEventListener('input', () => {
                    const q = input.value.trim().toLocaleLowerCase();
                    Array.from(table.tBodies[0].rows).forEach((row) => {
                        row.hidden = q !== '' && row.textContent.toLocaleLowerCase().indexOf(q) === -1;
                    });
                });
            });

            // Support queue: message template fills the text; select all visible rows; selected count.
            const template = app.querySelector('[data-region="cea-template"]');
            if (template) {
                template.addEventListener('change', () => {
                    const text = document.getElementById(template.dataset.target);
                    const option = template.options[template.selectedIndex];
                    if (text && option) {
                        text.value = option.dataset.text || '';
                    }
                });
            }
            const selcount = app.querySelector('[data-region="cea-selcount"]');
            const updateCount = () => {
                if (selcount) {
                    const n = app.querySelectorAll('[data-region="cea-sel"]:checked').length;
                    selcount.textContent = n ? selcount.dataset.template.replace('__n__', n) : '';
                }
            };
            app.querySelectorAll('[data-action="cea-selectall"]').forEach((box) => {
                box.addEventListener('change', () => {
                    const table = document.getElementById(box.dataset.target);
                    if (table) {
                        Array.from(table.tBodies[0].rows).forEach((row) => {
                            const sel = row.querySelector('[data-region="cea-sel"]');
                            if (sel && !row.hidden) {
                                sel.checked = box.checked;
                            }
                        });
                    }
                    updateCount();
                });
            });
            app.addEventListener('change', (e) => {
                if (e.target.matches && e.target.matches('[data-region="cea-sel"]')) {
                    updateCount();
                }
            });

            // Close open dropdowns when clicking elsewhere.
            document.addEventListener('click', (e) => {
                app.querySelectorAll('details.cea-dropdown[open]').forEach((d) => {
                    if (!d.contains(e.target)) {
                        d.removeAttribute('open');
                    }
                });
            });
        }
    };
});
