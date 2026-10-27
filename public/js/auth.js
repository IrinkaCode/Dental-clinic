/**
 * Страницы входа (login.html) и регистрации (register.html).
 *
 * Параметр ?next=booking.html возвращает пользователя после входа
 * на страницу, с которой его отправили на вход (например, с главной
 * по кнопке «Записаться на приём»).
 */
'use strict';

/** Страницы, на которые можно вернуться после входа, по ролям. */
const NEXT_PAGES = {
    patient: ['cabinet.html', 'booking.html', 'profile.html'],
    admin: ['admin.html'],
};

/** Куда направить после входа: на страницу из ?next=, если она разрешена для роли, иначе — домой. */
function pageAfterLogin(user, fallback) {
    const next = new URLSearchParams(location.search).get('next');
    return NEXT_PAGES[user.role]?.includes(next) ? next : fallback;
}

document.addEventListener('DOMContentLoaded', async () => {
    // Параметр next сохраняется при переходе между входом и регистрацией
    document.querySelectorAll('a[data-keep-next]').forEach((link) => {
        link.href = link.getAttribute('href') + location.search;
    });

    // Если пользователь уже вошёл — сразу отправляем его дальше
    try {
        const { user } = await api.get('me.php');
        if (user) {
            location.replace(pageAfterLogin(user, user.role === 'admin' ? 'admin.html' : 'cabinet.html'));
            return;
        }
    } catch {
        // сервер недоступен — ошибка покажется при отправке формы
    }

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', (event) => {
            event.preventDefault();
            submitAuthForm(loginForm, 'login.php', document.getElementById('login-error'), 'Входим…');
        });
    }

    const registerForm = document.getElementById('register-form');
    if (registerForm) {
        registerForm.addEventListener('submit', (event) => {
            event.preventDefault();

            // Быстрая проверка в браузере. Окончательно всё проверяет сервер.
            const data = ui.formData(registerForm);
            if (data.password !== data.password_confirm) {
                ui.clearErrors(registerForm);
                ui.showFieldErrors(registerForm, { password_confirm: 'Пароли не совпадают.' });
                return;
            }
            submitAuthForm(registerForm, 'register.php', document.getElementById('register-error'), 'Регистрируем…');
        });
    }
});

async function submitAuthForm(form, endpoint, errorBox, busyText) {
    const button = form.querySelector('button[type="submit"]');
    ui.clearErrors(form);
    ui.setBusy(button, true, busyText);

    try {
        const result = await api.post(endpoint, ui.formData(form));
        location.replace(pageAfterLogin(result.user, result.redirect));
    } catch (error) {
        ui.showAlert(errorBox, error.message);
        ui.showFieldErrors(form, error.fields || {});
        ui.setBusy(button, false);
    }
}
