/**
 * Раздел «Врачи»: список, добавление, изменение, оказываемые услуги.
 */
'use strict';

adminSections.doctors = {
    services: [],

    init() {
        document.getElementById('doctor-add').addEventListener('click', () => this.openDialog(null));

        this.dialog = admin.createDialog('doctor-dialog', {
            onSubmit: (data) => this.save(data),
            onDelete: (id) => this.remove(id),
            deleteConfirm: 'Удалить врача? Это возможно, только если к нему не было записей.',
        });
    },

    async load() {
        const { doctors, services } = await admin.get('doctors.php');
        this.services = services;
        const names = Object.fromEntries(services.map((s) => [s.id, s.name]));

        admin.fillTable(document.getElementById('doctors-body'), doctors, (doctor) => {
            const tr = document.createElement('tr');
            tr.append(
                admin.td(doctor.full_name, { sub: doctor.description || '' }),
                admin.td(doctor.specialty),
                admin.td(doctor.service_ids.map((id) => names[id]).join(', ') || '—'),
                doctor.is_active ? admin.td('Принимает') : admin.badgeCell('Не принимает', 'cancelled'),
                admin.actionsCell([['Изменить', () => this.openDialog(doctor)]]),
            );
            return tr;
        }, 'Врачей пока нет.');
    },

    openDialog(doctor) {
        // Флажки услуг строятся заново: список услуг мог измениться
        const list = document.getElementById('doctor-services');
        list.replaceChildren();
        for (const service of this.services) {
            const label = document.createElement('label');
            label.className = 'checkbox';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'service_ids';
            input.value = service.id;
            // defaultChecked, а не checked: при открытии окно сбрасывает форму (form.reset),
            // и флажки возвращаются именно к значению defaultChecked
            input.defaultChecked = Boolean(doctor?.service_ids.includes(service.id));
            const text = document.createElement('span');
            text.textContent = service.name + (service.is_active ? '' : ' (скрыта)');
            label.append(input, text);
            list.append(label);
        }

        const values = doctor
            ? { id: doctor.id, full_name: doctor.full_name, specialty: doctor.specialty,
                description: doctor.description, is_active: doctor.is_active }
            : { id: '', is_active: true };
        this.dialog.open(values, doctor ? 'Изменение врача' : 'Новый врач');
    },

    async save(data) {
        const checked = this.dialog.form.querySelectorAll('input[name="service_ids"]:checked');
        data.service_ids = [...checked].map((input) => Number(input.value));

        await admin.post('doctors.php', { ...data, action: data.id ? 'update' : 'create' });
        admin.notify(data.id ? 'Данные врача сохранены.' : 'Врач добавлен. Не забудьте задать расписание.');
        await this.load();
    },

    async remove(id) {
        await admin.post('doctors.php', { action: 'delete', id });
        admin.notify('Врач удалён.');
        await this.load();
    },
};
