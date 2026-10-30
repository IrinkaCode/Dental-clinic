/**
 * Подключается на закрытых страницах (кабинет, запись, профиль, админка).
 *
 * Скрипт проверяет вход и роль, подставляет имя пользователя
 * и обрабатывает кнопку «Выйти». Пока проверка идёт, страница скрыта.
 */
'use strict';

(async () => {
    const requiredRole = document.body.dataset.requireRole;

    let user;
    try {
        ({ user } = await api.get('me.php'));
    } catch (error) {
        document.body.classList.add('is-ready');
        document.body.innerHTML = '<main class="page"><div class="alert alert--error"></div></main>';
        document.querySelector('.alert').textContent = error.message;
        return;
    }

    if (!user) {
        // После входа пользователь вернётся на эту же страницу
        const page = location.pathname.split('/').pop() || 'cabinet.html';
        location.replace('login.html?next=' + encodeURIComponent(page));
        return;
    }
    if (requiredRole && user.role !== requiredRole) {
        location.replace(user.role === 'admin' ? 'admin.html' : 'cabinet.html');
        return;
    }

    window.currentUser = user;
    document.querySelectorAll('[data-user-name]').forEach((el) => {
        el.textContent = ui.shortName(user.full_name);
    });

    document.querySelectorAll('[data-logout]').forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            try {
                await api.post('logout.php');
            } finally {
                location.replace('index.html');   // после выхода — на главную
            }
        });
    });

    document.body.classList.add('is-ready');

    if (document.readyState === 'loading') {
        await new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    }
    document.dispatchEvent(new CustomEvent('user:ready', { detail: user }));
})();
