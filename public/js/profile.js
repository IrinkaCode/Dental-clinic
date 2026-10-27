/**
 * Страница «Мои данные» (profile.html): изменение профиля и смена пароля.
 */
'use strict';

document.addEventListener('user:ready', (event) => {
    const profileForm = document.getElementById('profile-form');
    const passwordForm = document.getElementById('password-form');

    fillProfile(profileForm, event.detail);

    // «Отменить» возвращает сохранённые значения
    document.getElementById('profile-reset').addEventListener('click', () => {
        ui.clearErrors(profileForm);
        fillProfile(profileForm, window.currentUser);
    });

    profileForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const button = profileForm.querySelector('button[type="submit"]');
        ui.clearErrors(profileForm);
        ui.setBusy(button, true, 'Сохраняем…');

        try {
            const { user } = await api.post('profile.php', ui.formData(profileForm));
            window.currentUser = user;
            fillProfile(profileForm, user);
            document.querySelectorAll('[data-user-name]').forEach((el) => {
                el.textContent = ui.shortName(user.full_name);
            });
            ui.showAlert(document.getElementById('profile-success'), 'Изменения сохранены.');
        } catch (error) {
            ui.showAlert(document.getElementById('profile-error'), error.message);
            ui.showFieldErrors(profileForm, error.fields || {});
        } finally {
            ui.setBusy(button, false);
        }
    });

    passwordForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const button = passwordForm.querySelector('button[type="submit"]');
        ui.clearErrors(passwordForm);
        ui.setBusy(button, true, 'Меняем…');

        try {
            await api.post('password.php', ui.formData(passwordForm));
            passwordForm.reset();
            ui.showAlert(document.getElementById('password-success'), 'Пароль изменён.');
        } catch (error) {
            ui.showAlert(document.getElementById('password-error'), error.message);
            ui.showFieldErrors(passwordForm, error.fields || {});
        } finally {
            ui.setBusy(button, false);
        }
    });
});

function fillProfile(form, user) {
    form.elements.full_name.value = user.full_name || '';
    form.elements.email.value = user.email || '';
    form.elements.phone.value = user.phone || '';
    form.elements.birth_date.value = user.birth_date || '';
}
