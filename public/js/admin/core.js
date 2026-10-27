/**
 * Ядро панели администратора: переключение разделов, окна с формами,
 * построение таблиц и уведомления.
 *
 * Каждый раздел — отдельный файл js/admin/<раздел>.js, который регистрирует себя:
 *     adminSections.users = {
 *         init() { ... },   // один раз: обработчики кнопок и фильтров
 *         load() { ... },   // при каждом открытии раздела: загрузка данных
 *     };
 * Раздел открывается по адресу: admin.html#users
 */
'use strict';

const adminSections = {};
const DEFAULT_SECTION = 'appointments';

document.addEventListener('user:ready', (event) => {
    window.currentAdmin = event.detail;

    for (const section of Object.values(adminSections)) {
        section.init?.();
    }
    window.addEventListener('hashchange', showSection);
    showSection();
});

function showSection() {
    let name = location.hash.slice(1);
    if (!adminSections[name]) {
        name = DEFAULT_SECTION;
    }

    document.querySelectorAll('.admin-section').forEach((section) => {
        section.hidden = section.dataset.section !== name;
    });
    document.querySelectorAll('.admin__nav a').forEach((link) => {
        if (link.getAttribute('href') === '#' + name) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    admin.hideNotice();
    Promise.resolve(adminSections[name].load()).catch((error) => admin.notify(error.message, 'error'));
}

const admin = {
    // -----------------------------------------------------------------
    //  Запросы к api/admin/
    // -----------------------------------------------------------------
    get(path, params = {}) {
        const query = new URLSearchParams(params).toString();
        return api.get('admin/' + path + (query ? '?' + query : ''));
    },

    post(path, data) {
        return api.post('admin/' + path, data);
    },

    // -----------------------------------------------------------------
    //  Уведомление вверху страницы
    // -----------------------------------------------------------------
    notify(message, type = 'success') {
        const box = document.getElementById('admin-alert');
        box.className = 'alert alert--' + type;
        box.textContent = message;
        box.hidden = false;
        clearTimeout(this.noticeTimer);
        if (type === 'success') {
            this.noticeTimer = setTimeout(() => { box.hidden = true; }, 4000);
        }
    },

    hideNotice() {
        document.getElementById('admin-alert').hidden = true;
    },

    // -----------------------------------------------------------------
    //  Построение таблиц. Текст всегда через textContent — защита от XSS.
    // -----------------------------------------------------------------

    /** Ячейка: text — строка; sub — серая подпись второй строкой. */
    td(text, { className = '', sub = '' } = {}) {
        const cell = document.createElement('td');
        if (className) {
            cell.className = className;
        }
        cell.append(text ?? '—');
        if (sub) {
            const small = document.createElement('span');
            small.className = 'cell-sub';
            small.textContent = sub;
            cell.append(small);
        }
        return cell;
    },

    /** Ячейка со значком статуса. */
    badgeCell(text, modifier) {
        const cell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'status' + (modifier ? ' status--' + modifier : '');
        badge.textContent = text;
        cell.append(badge);
        return cell;
    },

    /** Ячейка с кнопками: [['Изменить', () => ..., 'ghost'], ...] */
    actionsCell(buttons) {
        const cell = document.createElement('td');
        const wrap = document.createElement('div');
        wrap.className = 'table__actions';
        for (const [text, onClick, style = 'ghost'] of buttons) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn--${style} btn--small`;
            button.textContent = text;
            button.addEventListener('click', onClick);
            wrap.append(button);
        }
        cell.append(wrap);
        return cell;
    },

    /** Заменяет строки таблицы; при пустом списке показывает сообщение. */
    fillTable(tbody, items, makeRow, emptyText) {
        tbody.replaceChildren();
        if (items.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = tbody.closest('table').querySelectorAll('thead th').length;
            cell.className = 'table__empty';
            cell.textContent = emptyText;
            row.append(cell);
            tbody.append(row);
            return;
        }
        for (const item of items) {
            tbody.append(makeRow(item));
        }
    },

    /** Заполняет <select> вариантами [{value, text}], сохраняя выбранное значение. */
    fillSelect(select, options, { placeholder = null, keep = true } = {}) {
        const previous = select.value;
        select.replaceChildren();
        if (placeholder !== null) {
            select.append(new Option(placeholder, ''));
        }
        for (const { value, text } of options) {
            select.append(new Option(text, value));
        }
        if (keep && [...select.options].some((o) => o.value === previous)) {
            select.value = previous;
        }
    },

    // -----------------------------------------------------------------
    //  Окно с формой (<dialog>)
    // -----------------------------------------------------------------

    /**
     * Подключает окно. Возвращает { form, open(values, title) }.
     * onSubmit(data) и onDelete(id) должны вернуть Promise; при ошибке
     * сервера сообщения показываются прямо в окне, под полями.
     */
    createDialog(id, { onSubmit, onDelete, deleteConfirm }) {
        const dialog = document.getElementById(id);
        const form = dialog.querySelector('form');
        const errorBox = form.querySelector('.alert');
        const deleteButton = form.querySelector('[data-action="delete"]');

        form.querySelector('[data-action="close"]').addEventListener('click', () => dialog.close());

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            ui.clearErrors(form);
            ui.setBusy(button, true, 'Сохраняем…');
            try {
                await onSubmit(ui.formData(form));
                dialog.close();
            } catch (error) {
                ui.showAlert(errorBox, error.message);
                ui.showFieldErrors(form, error.fields || {});
            } finally {
                ui.setBusy(button, false);
            }
        });

        deleteButton.addEventListener('click', async () => {
            if (!confirm(deleteConfirm)) {
                return;
            }
            ui.clearErrors(form);
            try {
                await onDelete(Number(form.elements.id.value));
                dialog.close();
            } catch (error) {
                ui.showAlert(errorBox, error.message);
            }
        });

        return {
            form,
            open(values, title) {
                form.reset();
                ui.clearErrors(form);
                dialog.querySelector('h2').textContent = title;
                admin.fillForm(form, values);
                deleteButton.hidden = !values.id;
                dialog.showModal();
            },
        };
    },

    /** Записывает значения в поля формы по атрибуту name. */
    fillForm(form, values) {
        for (const element of form.elements) {
            if (!element.name || !(element.name in values)) {
                continue;
            }
            if (element.type === 'checkbox') {
                element.checked = Boolean(values[element.name]);
            } else {
                element.value = values[element.name] ?? '';
            }
        }
    },

    // -----------------------------------------------------------------
    //  Разное
    // -----------------------------------------------------------------

    /** Вызывает fn не чаще, чем раз в delay мс (для поиска при вводе). */
    debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    },

    /** Сегодняшняя дата 'YYYY-MM-DD' по местному времени. */
    today() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    },
};
