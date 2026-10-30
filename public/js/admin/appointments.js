/**
 * Раздел «Записи»: показатели, фильтры, отметка «Состоялся», создание и перенос записей
 */
'use strict';

adminSections.appointments = {
    doctors: [],
    services: [],
    patients: [],

    init() {
        const filter = document.getElementById('appointments-filter');
        filter.elements.date_from.value = admin.today();
        filter.addEventListener('input', admin.debounce(() => this.loadList()));
        filter.addEventListener('submit', (e) => e.preventDefault());

        // Карточка «Ожидают отметки»
        document.getElementById('show-overdue').addEventListener('click', () => {
            filter.reset();
            filter.elements.status.value = 'overdue';
            this.loadList();
        });

        document.getElementById('appointment-add').addEventListener('click', () => this.openDialog(null));

        this.dialog = admin.createDialog('appointment-dialog', {
            onSubmit: (data) => this.save(data),
            onDelete: (id) => this.remove(id),
            deleteConfirm: 'Удалить запись без возможности восстановления? Обычно достаточно статуса «Отменён».',
        });
        this.dialog.form.elements.service_id.addEventListener('change', () => this.fillDoctors());
    },

    async load() {
        await this.loadReferences();
        await Promise.all([this.loadStats(), this.loadList()]);
    },

    /** Справочники: врачи, услуги, пациенты (для фильтра и окна записи). */
    async loadReferences() {
        const [{ doctors, services }, { users }] = await Promise.all([
            admin.get('doctors.php'),
            admin.get('users.php', { role: 'patient' }),
        ]);
        this.doctors = doctors;
        this.services = services;
        this.patients = users.sort((a, b) => a.full_name.localeCompare(b.full_name, 'ru'));

        admin.fillSelect(document.getElementById('ap-doctor'),
            doctors.map((d) => ({ value: d.id, text: d.full_name })), { placeholder: 'Все' });
    },

    async loadStats() {
        const stats = await admin.get('stats.php');
        document.querySelectorAll('[data-stat]').forEach((el) => {
            el.textContent = stats[el.dataset.stat];
        });
    },

    async loadList() {
        const filter = document.getElementById('appointments-filter');
        const params = Object.fromEntries(new FormData(filter));
        const { appointments } = await admin.get('appointments.php', params);

        admin.fillTable(document.getElementById('appointments-body'), appointments,
            (item) => this.makeRow(item), 'Записей не найдено. Измените условия поиска.');
        document.getElementById('appointments-count').textContent = 'Найдено: ' + appointments.length;
    },

    makeRow(item) {
        const tr = document.createElement('tr');
        const overdue = item.status === 'planned' && item.is_past;

        tr.append(
            admin.td(fmt.dateTime(item.start_at), { className: 'nowrap', sub: 'до ' + fmt.time(item.end_at) }),
            admin.td(item.patient_name, { sub: item.patient_phone }),
            admin.td(fmt.initials(item.doctor_name), { className: 'nowrap' }),
            admin.td(item.service_name, { sub: item.comment || '' }),
            overdue ? admin.badgeCell('Ожидает отметки', 'overdue') : admin.badgeCell(STATUS_LABELS[item.status], item.status),
        );

        const buttons = [];
        if (item.status === 'planned') {
            buttons.push(['Состоялся', () => this.setStatus(item.id, 'completed')]);
        }
        buttons.push(['Изменить', () => this.openDialog(item)]);
        tr.append(admin.actionsCell(buttons));
        return tr;
    },

    openDialog(item) {
        const form = this.dialog.form;

        // Пациента можно выбрать только при создании записи
        const patientSelect = form.elements.user_id;
        if (item) {
            admin.fillSelect(patientSelect, [{ value: item.user_id, text: item.patient_name }], { keep: false });
        } else {
            admin.fillSelect(patientSelect, this.patients.map((p) => ({
                value: p.id,
                text: `${p.full_name}${p.phone ? ' — ' + p.phone : ''}`,
            })), { placeholder: 'Выберите пациента', keep: false });
        }
        patientSelect.disabled = Boolean(item);

        admin.fillSelect(form.elements.service_id,
            this.services.filter((s) => s.is_active).map((s) => ({ value: s.id, text: s.name })),
            { placeholder: 'Выберите услугу', keep: false });

        const values = item
            ? {
                id: item.id,
                user_id: item.user_id,
                service_id: item.service_id,
                date: item.start_at.slice(0, 10),
                time: fmt.time(item.start_at),
                status: item.status,
                comment: item.comment,
            }
            : { id: '', date: admin.today(), status: 'planned' };

        this.dialog.open(values, item ? 'Запись № ' + item.id : 'Новая запись');
        this.fillDoctors(item ? item.doctor_id : null);
    },

    /** Врачи, оказывающие выбранную в окне услугу. */
    fillDoctors(selectedId = null) {
        const form = this.dialog.form;
        const serviceId = Number(form.elements.service_id.value);
        const suitable = this.doctors.filter((d) => d.is_active && d.service_ids.includes(serviceId));

        admin.fillSelect(form.elements.doctor_id,
            suitable.map((d) => ({ value: d.id, text: `${d.full_name} (${d.specialty})` })),
            { placeholder: serviceId ? 'Выберите врача' : 'Сначала выберите услугу' });

        if (selectedId) {
            form.elements.doctor_id.value = selectedId;
        } else if (suitable.length === 1) {
            form.elements.doctor_id.value = suitable[0].id;
        }
    },

    async save(data) {
        await admin.post('appointments.php', { ...data, action: data.id ? 'update' : 'create' });
        admin.notify(data.id ? 'Запись изменена.' : 'Запись создана.');
        await Promise.all([this.loadStats(), this.loadList()]);
    },

    async setStatus(id, status) {
        try {
            await admin.post('appointments.php', { action: 'status', id, status });
            admin.notify('Статус записи изменён.');
            await Promise.all([this.loadStats(), this.loadList()]);
        } catch (error) {
            admin.notify(error.message, 'error');
        }
    },

    async remove(id) {
        await admin.post('appointments.php', { action: 'delete', id });
        admin.notify('Запись удалена.');
        await Promise.all([this.loadStats(), this.loadList()]);
    },
};
