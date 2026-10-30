/**
 * Раздел «Пользователи»: поиск, создание, изменение, блокировка и удаление учётных записей
 */
'use strict';

adminSections.users = {
    init() {
        const filter = document.getElementById('users-filter');
        filter.addEventListener('input', admin.debounce(() => this.load()));
        filter.addEventListener('submit', (e) => e.preventDefault());

        document.getElementById('user-add').addEventListener('click', () => this.openDialog(null));

        this.dialog = admin.createDialog('user-dialog', {
            onSubmit: (data) => this.save(data),
            onDelete: (id) => this.remove(id),
            deleteConfirm: 'Удалить пользователя? Все его записи на приём тоже будут удалены. '
                + 'Если нужно только закрыть доступ — используйте блокировку.',
        });
    },

    async load() {
        const params = Object.fromEntries(new FormData(document.getElementById('users-filter')));
        const { users } = await admin.get('users.php', params);

        admin.fillTable(document.getElementById('users-body'), users,
            (user) => this.makeRow(user), 'Пользователи не найдены.');
        document.getElementById('users-count').textContent = 'Найдено: ' + users.length;
    },

    makeRow(user) {
        const tr = document.createElement('tr');
        const isMe = user.id === window.currentAdmin.id;

        tr.append(
            admin.td(String(user.id)),
            admin.td(user.full_name, { sub: isMe ? 'это вы' : '' }),
            admin.td(user.email),
            admin.td(user.phone || '—', { className: 'nowrap' }),
            user.role === 'admin' ? admin.badgeCell('Администратор', 'admin') : admin.td('Пациент'),
            user.is_active ? admin.td('Активен') : admin.badgeCell('Заблокирован', 'blocked'),
            admin.td(fmt.parse(user.created_at).toLocaleDateString('ru-RU'), { className: 'nowrap' }),
            admin.actionsCell([['Изменить', () => this.openDialog(user)]]),
        );
        return tr;
    },

    openDialog(user) {
        const values = user
            ? { ...user, is_blocked: !user.is_active, password: '' }
            : { id: '', role: 'patient', is_blocked: false };

        this.dialog.open(values, user ? 'Изменение пользователя' : 'Новый пользователь');
        this.dialog.form.querySelector('[data-hint="password"]').textContent = user
            ? 'Оставьте пустым, чтобы не менять.'
            : 'Не менее 8 символов, буквы и цифры.';
    },

    async save(data) {
        await admin.post('users.php', { ...data, action: data.id ? 'update' : 'create' });
        admin.notify(data.id ? 'Данные пользователя сохранены.' : 'Пользователь создан.');
        await this.load();
    },

    async remove(id) {
        await admin.post('users.php', { action: 'delete', id });
        admin.notify('Пользователь удалён.');
        await this.load();
    },
};
