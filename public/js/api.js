/**
 * Обращение к PHP API и общие функции для форм
 */
'use strict';

/** Ошибка, которую вернул сервер. */
class ApiError extends Error {
    constructor(message, status, fields = {}, code = null) {
        super(message);
        this.status = status;   // HTTP-код ответа (0 — нет связи с сервером)
        this.fields = fields;   // ошибки по полям формы
        this.code = code;       // машинный код: 'csrf', 'unauthorized', 'forbidden'
    }
}

const api = {
    csrfToken: null,

    async request(path, { method = 'GET', data = null } = {}, retry = true) {
        const options = {
            method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        };

        if (method !== 'GET') {
            if (!this.csrfToken) {
                await this.loadCsrf();
            }
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = this.csrfToken;
            options.body = JSON.stringify(data ?? {});
        }

        let response;
        try {
            response = await fetch('api/' + path, options);
        } catch {
            throw new ApiError('Нет связи с сервером. Проверьте, что приложение запущено.', 0);
        }

        let body = null;
        try {
            body = await response.json();
        } catch {
            // сервер вернул не JSON — обработаем ниже как ошибку
        }

        if (body && body.csrf_token) {
            this.csrfToken = body.csrf_token;
        }

        if (!response.ok) {
            // Токен устарел (например, после перезапуска сервера) — получаем новый и повторяем один раз
            if (body?.code === 'csrf' && retry) {
                this.csrfToken = null;
                return this.request(path, { method, data }, false);
            }
            throw new ApiError(
                body?.error || `Ошибка сервера (${response.status}).`,
                response.status,
                body?.fields || {},
                body?.code || null,
            );
        }
        return body;
    },

    async loadCsrf() {
        const body = await this.request('csrf.php');
        this.csrfToken = body.csrf_token;
    },

    get(path) {
        return this.request(path);
    },

    post(path, data) {
        return this.request(path, { method: 'POST', data });
    },

    async upload(path, formData, retry = true) {
        if (!this.csrfToken) {
            await this.loadCsrf();
        }
        let response;
        try {
            response = await fetch('api/' + path, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': this.csrfToken },
                body: formData,
            });
        } catch {
            throw new ApiError('Нет связи с сервером. Проверьте, что приложение запущено.', 0);
        }

        let body = null;
        try {
            body = await response.json();
        } catch {

        }

        if (body && body.csrf_token) {
            this.csrfToken = body.csrf_token;
        }
        if (!response.ok) {
            if (body?.code === 'csrf' && retry) {
                this.csrfToken = null;
                return this.upload(path, formData, false);
            }
            throw new ApiError(body?.error || `Ошибка сервера (${response.status}).`, response.status, body?.fields || {}, body?.code || null);
        }
        return body;
    },
};

/** Помощники для форм. */
const ui = {
    /** Данные формы как объект. Флажки: true/false. */
    formData(form) {
        const data = Object.fromEntries(new FormData(form));
        form.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            data[box.name] = box.checked;
        });
        return data;
    },

    /** Показывает ошибки под полями */
    showFieldErrors(form, fields) {
        for (const [name, message] of Object.entries(fields)) {
            const errorEl = form.querySelector(`[data-error-for="${name}"]`);
            if (errorEl) {
                errorEl.textContent = message;
            }
            const input = form.elements[name];
            if (input) {
                input.setAttribute('aria-invalid', 'true');
            }
        }
        const first = form.querySelector('[aria-invalid="true"]');
        if (first) {
            first.focus();
        }
    },

    clearErrors(form) {
        form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
        form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
        form.querySelectorAll('.alert').forEach((el) => { el.hidden = true; });
    },

    showAlert(element, message) {
        element.textContent = message;
        element.hidden = false;
    },

    /** Блокирует кнопку на время запроса и меняет её текст. */
    setBusy(button, busy, busyText = 'Подождите…') {
        if (busy) {
            button.dataset.text = button.textContent;
            button.textContent = busyText;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.text || button.textContent;
            button.disabled = false;
        }
    },

    /** «Иванова Анна Сергеевна» → «Иванова Анна» */
    shortName(fullName) {
        return (fullName || '').split(' ').slice(0, 2).join(' ');
    },

    /** «Смирнова Елена Викторовна» → «СЕ» (инициалы для заглушки аватара). */
    avatarInitials(fullName) {
        const parts = (fullName || '').trim().split(/\s+/).filter(Boolean);
        return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
    },

    /**
     * Кружок-аватар: фотография, если она есть, иначе инициалы.
     */
    avatar(fullName, photoUrl, className) {
        const el = document.createElement('div');
        el.className = className;
        if (photoUrl) {
            const img = document.createElement('img');
            img.src = photoUrl;
            img.alt = '';
            img.loading = 'lazy';
            el.append(img);
        } else {
            el.textContent = this.avatarInitials(fullName);
        }
        return el;
    },

    /**
     * Фото на всю ширину карточки
     * настоящая фотография или плейсхолдер с инициалами того же размера и формы
     */
    coverPhoto(fullName, photoUrl, className) {
        if (photoUrl) {
            const img = document.createElement('img');
            img.className = className;
            img.src = photoUrl;
            img.alt = '';
            img.loading = 'lazy';
            return img;
        }
        const el = document.createElement('div');
        el.className = `${className} ${className}--placeholder`;
        el.textContent = this.avatarInitials(fullName);
        return el;
    },
};

/**
 * Форматирование дат, времени и сумм.
 * Даты приходят из API в виде 'YYYY-MM-DD HH:MM'.
 */
const fmt = {
    parse(value) {
        const [date, time = '00:00'] = value.split(' ');
        const [y, m, d] = date.split('-').map(Number);
        const [h, min] = time.split(':').map(Number);
        return new Date(y, m - 1, d, h, min);
    },

    /** '19 октября' */
    dayMonth(value) {
        return this.parse(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    },

    /** '15 окт' */
    dayShort(value) {
        return this.parse(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '');
    },

    /** 'пн' */
    weekday(value) {
        return this.parse(value).toLocaleDateString('ru-RU', { weekday: 'short' });
    },

    /** '12:00' */
    time(value) {
        return value.slice(11, 16);
    },

    /** '02.10.2026, 10:30' */
    dateTime(value) {
        return this.parse(value).toLocaleDateString('ru-RU') + ', ' + this.time(value);
    },

    /** '4 500 ₽' */
    money(amount) {
        return new Intl.NumberFormat('ru-RU').format(amount) + ' ₽';
    },

    /** plural(5, ['минута', 'минуты', 'минут']) → 'минут' */
    plural(n, forms) {
        const n10 = n % 10;
        const n100 = n % 100;
        if (n10 === 1 && n100 !== 11) return forms[0];
        if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) return forms[1];
        return forms[2];
    },

    /** '30 минут' */
    duration(minutes) {
        return minutes + ' ' + this.plural(minutes, ['минута', 'минуты', 'минут']);
    },

    /** 'Смирнова Елена Викторовна' → 'Смирнова Е. В.' */
    initials(fullName) {
        const [last, ...rest] = (fullName || '').split(' ');
        return [last, ...rest.map((part) => part[0] + '.')].join(' ');
    },
};

/** Подписи статусов записи. */
const STATUS_LABELS = {
    planned: 'Запланирован',
    completed: 'Состоялся',
    cancelled: 'Отменён',
};
