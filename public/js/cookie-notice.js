/**
 * Уведомление об использовании cookie (внизу страницы).
 *
 * Сайт использует только одну техническую cookie (идентификатор сессии для входа
 * и защиты форм), поэтому это уведомление, а не запрос согласия: без этой cookie
 * вход не работает, и отказаться от неё нельзя. Подробности — в privacy.html#cookies.
 *
 * Факт, что человек увидел уведомление, запоминается в памяти браузера
 * (localStorage, не cookie) на 12 месяцев. Если память браузера недоступна
 * (например, в приватном режиме), уведомление просто показывается снова.
 */
'use strict';

(() => {
    const STORAGE_KEY = 'cookieNoticeSeen';
    const REMEMBER_DAYS = 365;

    function alreadySeen() {
        try {
            const savedAt = Number(localStorage.getItem(STORAGE_KEY));
            return savedAt > 0 && Date.now() - savedAt < REMEMBER_DAYS * 24 * 60 * 60 * 1000;
        } catch {
            return false;
        }
    }

    function remember() {
        try {
            localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch {
            // память браузера недоступна — уведомление покажется при следующем заходе
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (alreadySeen()) {
            return;
        }

        const notice = document.createElement('div');
        notice.className = 'cookie-notice';
        notice.setAttribute('role', 'region');
        notice.setAttribute('aria-label', 'Уведомление об использовании cookie');

        const text = document.createElement('p');
        text.append(
            'Сайт использует файлы cookie, необходимые для входа и работы личного кабинета. '
            + 'Мы не используем cookie для рекламы и аналитики. '
        );
        const more = document.createElement('a');
        more.href = 'privacy.html#cookies';
        more.textContent = 'Подробнее';
        text.append(more);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--primary btn--small';
        button.textContent = 'Понятно';
        button.addEventListener('click', () => {
            remember();
            notice.remove();
        });

        notice.append(text, button);
        document.body.append(notice);
    });
})();
