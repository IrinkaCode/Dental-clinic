/**
 * Раздел «Услуги»: список, добавление, изменение, скрытие.
 */
'use strict';

adminSections.services = {
    init() {
        // Варианты длительности: 15 минут … 4 часа
        const select = document.querySelector('#service-dialog [name="duration_min"]');
        for (let minutes = 15; minutes <= 240; minutes += 15) {
            select.append(new Option(fmt.duration(minutes), minutes));
        }

        document.getElementById('service-add').addEventListener('click', () => this.openDialog(null));

        this.dialog = admin.createDialog('service-dialog', {
            onSubmit: (data) => this.save(data),
            onDelete: (id) => this.remove(id),
            deleteConfirm: 'Удалить услугу? Это возможно, только если на неё не было записей.',
        });
    },

    async load() {
        const { services } = await admin.get('services.php');

        admin.fillTable(document.getElementById('services-body'), services, (service) => {
            const tr = document.createElement('tr');
            tr.append(
                admin.td(service.name, { sub: service.description || '' }),
                admin.td(fmt.duration(service.duration_min), { className: 'nowrap' }),
                admin.td(fmt.money(service.price), { className: 'num' }),
                admin.td(String(service.doctors_count), { className: 'num' }),
                service.is_active ? admin.td('Доступна') : admin.badgeCell('Скрыта', 'cancelled'),
                admin.actionsCell([['Изменить', () => this.openDialog(service)]]),
            );
            return tr;
        }, 'Услуг пока нет.');
    },

    openDialog(service) {
        const values = service
            ? { ...service }
            : { id: '', price: 0, duration_min: 30, is_active: true };
        this.dialog.open(values, service ? 'Изменение услуги' : 'Новая услуга');
    },

    async save(data) {
        await admin.post('services.php', { ...data, action: data.id ? 'update' : 'create' });
        admin.notify(data.id ? 'Услуга сохранена.' : 'Услуга добавлена. Отметьте врачей, которые её оказывают.');
        await this.load();
    },

    async remove(id) {
        await admin.post('services.php', { action: 'delete', id });
        admin.notify('Услуга удалена.');
        await this.load();
    },
};
