/**
 * Раздел «Контакты клиники».
 */
'use strict';

adminSections.clinic = {
    init() {
        const form = document.getElementById('clinic-form');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            ui.clearErrors(form);
            ui.setBusy(button, true, 'Сохраняем…');
            try {
                await admin.post('clinic.php', ui.formData(form));
                admin.notify('Контакты клиники сохранены.');
            } catch (error) {
                ui.showAlert(document.getElementById('clinic-error'), error.message);
                ui.showFieldErrors(form, error.fields || {});
            } finally {
                ui.setBusy(button, false);
            }
        });
    },

    async load() {
        const form = document.getElementById('clinic-form');
        ui.clearErrors(form);
        const { clinic } = await admin.get('clinic.php');
        admin.fillForm(form, clinic);
    },
};
