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
 * Global search (Ctrl+K): students, courses and teachers, with keyboard navigation.
 *
 * Plain AMD module (no ES module syntax), so it runs without a Grunt build.
 *
 * @module     local_ceduanalytics/search
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    'use strict';

    const ICONS = {student: 'fa-user-graduate', teacher: 'fa-chalkboard-user', course: 'fa-book-open'};

    return {
        /**
         * Initialise.
         */
        init: function() {
            const input = document.getElementById('cea-search-input');
            const list = document.getElementById('cea-search-results');
            const status = document.querySelector('[data-region="cea-search-status"]');
            if (!input || !list || input.dataset.ceaInit) {
                return;
            }
            input.dataset.ceaInit = '1';
            let timer = null;
            let seq = 0;
            let active = -1;

            const items = () => Array.from(list.querySelectorAll('[role="option"]'));

            const close = () => {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            };

            const message = (text) => {
                list.innerHTML = '';
                const li = document.createElement('li');
                li.className = 'cea-search-msg';
                li.textContent = text;
                list.appendChild(li);
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                status.textContent = text;
            };

            const highlight = (index) => {
                const all = items();
                all.forEach((el) => el.classList.remove('is-active'));
                if (!all.length) {
                    return;
                }
                active = (index + all.length) % all.length;
                all[active].classList.add('is-active');
                all[active].scrollIntoView({block: 'nearest'});
                input.setAttribute('aria-activedescendant', all[active].id);
            };

            const render = (results) => {
                list.innerHTML = '';
                active = -1;
                if (!results.length) {
                    message(input.dataset.empty);
                    return;
                }
                results.forEach((r, i) => {
                    const li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.id = 'cea-search-opt-' + i;
                    const a = document.createElement('a');
                    a.href = r.url;
                    a.tabIndex = -1;
                    const icon = document.createElement('i');
                    icon.className = 'fa-solid ' + (ICONS[r.type] || 'fa-circle');
                    icon.setAttribute('aria-hidden', 'true');
                    const text = document.createElement('span');
                    text.className = 'cea-search-text';
                    const title = document.createElement('strong');
                    title.textContent = r.title;
                    const sub = document.createElement('small');
                    sub.textContent = r.subtitle;
                    text.appendChild(title);
                    text.appendChild(sub);
                    const type = document.createElement('span');
                    type.className = 'cea-badge cea-badge-soft';
                    type.textContent = r.typelabel;
                    a.appendChild(icon);
                    a.appendChild(text);
                    a.appendChild(type);
                    li.appendChild(a);
                    list.appendChild(li);
                });
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                status.textContent = results.length + '';
            };

            const search = () => {
                const query = input.value.trim();
                if (query.length < 2) {
                    if (query.length) {
                        message(input.dataset.short);
                    } else {
                        close();
                    }
                    return;
                }
                const mine = ++seq;
                message(input.dataset.loading);
                Ajax.call([{methodname: 'local_ceduanalytics_search', args: {query: query}}])[0]
                    .then((response) => {
                        if (mine === seq) {
                            render(response.results);
                        }
                        return null;
                    })
                    .catch(() => {
                        if (mine === seq) {
                            message(input.dataset.empty);
                        }
                    });
            };

            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(search, 250);
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlight(active + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlight(active - 1);
                } else if (e.key === 'Enter') {
                    const all = items();
                    const target = all[active >= 0 ? active : 0];
                    if (target) {
                        e.preventDefault();
                        window.location.href = target.querySelector('a').href;
                    }
                } else if (e.key === 'Escape') {
                    close();
                }
            });

            input.addEventListener('focus', () => {
                if (list.children.length && input.value.trim().length >= 2) {
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('[data-region="cea-search"]')) {
                    close();
                }
            });
        }
    };
});
