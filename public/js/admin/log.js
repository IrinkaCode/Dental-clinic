/**
 * Раздел «Журнал действий»
 */
'use strict';

const LOG_ACTIONS = {
    login: 'Вход',
    login_failed: 'Неудачная попытка входа',
    logout: 'Выход',
    register: 'Регистрация',
    profile_update: 'Изменение своих данных',
    password_change: 'Смена пароля',
    appointment_create: 'Запись на приём',
    appointment_cancel: 'Отмена записи пациентом',
    admin_user_create: 'Создан пользователь',
    admin_user_update: 'Изменён пользователь',
    admin_user_delete: 'Удалён пользователь',
    admin_appointment_create: 'Создана запись',
    admin_appointment_update: 'Изменена запись',
    admin_appointment_status: 'Изменён статус записи',
    admin_appointment_delete: 'Удалена запись',
    admin_doctor_create: 'Добавлен врач',
    admin_doctor_update: 'Изменён врач',
    admin_doctor_delete: 'Удалён врач',
    admin_service_create: 'Добавлена услуга',
    admin_service_update: 'Изменена услуга',
    admin_service_delete: 'Удалена услуга',
    admin_schedule_update: 'Изменено расписание',
    admin_clinic_update: 'Изменены контакты клиники',
};

const LOG_TABLES = {
    users: 'пользователь',
    appointments: 'запись',
    doctors: 'врач',
    services: 'услуга',
    clinic_info: 'контакты',
};

adminSections.log = {
    async load() {
        const { log } = await admin.get('log.php');

        admin.fillTable(document.getElementById('log-body'), log, (entry) => {
            const tr = document.createElement('tr');
            const who = entry.user_name
                ? entry.user_name + (entry.role === 'admin' ? ' (администратор)' : '')
                : 'удалённый пользователь';
            const object = entry.table_name
                ? (LOG_TABLES[entry.table_name] || entry.table_name) + (entry.record_id ? ' № ' + entry.record_id : '')
                : '—';

            tr.append(
                admin.td(fmt.dateTime(entry.created_at), { className: 'nowrap' }),
                admin.td(who),
                admin.td(LOG_ACTIONS[entry.action] || entry.action),
                admin.td(object),
            );
            return tr;
        }, 'Журнал пуст.');
    },
};
