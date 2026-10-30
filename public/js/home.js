/**
 * Главная страница: прайс-лист, врачи и контакты из базы
 */
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    setupAccountLinks();
    loadPrices();
    loadDoctors();
    loadClinic();
});

/** «Войти» → «Личный кабинет», если пользователь уже вошёл. */
async function setupAccountLinks() {
    let user = null;
    try {
        ({ user } = await api.get('me.php'));
    } catch {

    }

    const account = document.getElementById('account-link');
    let bookingHref = 'login.html?next=booking.html';

    if (user?.role === 'admin') {
        account.textContent = 'Панель администратора';
        account.href = 'admin.html';
        bookingHref = 'admin.html#appointments';
    } else if (user) {
        account.textContent = 'Личный кабинет';
        account.href = 'cabinet.html';
        bookingHref = 'booking.html';
    }

    document.querySelectorAll('[data-booking-link]').forEach((link) => {
        link.href = bookingHref;
    });
}

async function loadPrices() {
    const status = document.getElementById('prices-status');
    try {
        const { services } = await api.get('services.php');
        const list = document.getElementById('price-list');

        for (const service of services) {
            const li = document.createElement('li');
            li.className = 'price-row';

            const info = document.createElement('div');
            const name = document.createElement('h3');
            name.className = 'price-row__name';
            name.textContent = service.name;
            info.append(name);
            if (service.description) {
                const description = document.createElement('p');
                description.className = 'price-row__description';
                description.textContent = service.description;
                info.append(description);
            }

            const duration = document.createElement('span');
            duration.className = 'price-row__duration';
            duration.textContent = fmt.duration(service.duration_min);

            const price = document.createElement('span');
            price.className = 'price-row__price';
            price.textContent = 'от\u00A0' + fmt.money(service.price);

            li.append(info, duration, price);
            list.append(li);
        }
        status.hidden = services.length > 0;
        status.textContent = 'Список услуг скоро появится.';
    } catch {
        status.textContent = 'Не удалось загрузить услуги. Обновите страницу.';
    }
}

async function loadDoctors() {
    const status = document.getElementById('doctors-status');
    try {
        const { doctors } = await api.get('doctors.php');
        const grid = document.getElementById('doctors-list');

        for (const doctor of doctors) {
            const card = document.createElement('article');
            card.className = 'doctor-card';
            card.append(ui.coverPhoto(doctor.full_name, doctor.photo, 'doctor-card__photo'));

            const body = document.createElement('div');
            body.className = 'doctor-card__body';

            const name = document.createElement('h3');
            name.textContent = doctor.full_name;
            const specialty = document.createElement('p');
            specialty.className = 'doctor-card__specialty';
            specialty.textContent = doctor.specialty;
            body.append(name, specialty);

            if (doctor.description) {
                const description = document.createElement('p');
                description.textContent = doctor.description;
                body.append(description);
            }
            if (doctor.services.length) {
                const services = document.createElement('p');
                services.className = 'doctor-card__services';
                services.textContent = 'Принимает: ' + doctor.services.join(', ').toLowerCase();
                body.append(services);
            }
            card.append(body);
            grid.append(card);
        }
        status.hidden = doctors.length > 0;
        status.textContent = 'Информация о врачах скоро появится.';
    } catch {
        status.textContent = 'Не удалось загрузить список врачей. Обновите страницу.';
    }
}

async function loadClinic() {
    try {
        const { clinic } = await api.get('clinic.php');
        const tel = 'tel:' + (clinic.phone || '').replace(/[^\d+]/g, '');

        document.querySelectorAll('[data-clinic]').forEach((el) => {
            const key = el.dataset.clinic;
            if (!clinic[key]) {
                return;
            }
            el.textContent = clinic[key];
            if (el.tagName === 'A') {
                el.href = key === 'phone' ? tel : 'mailto:' + clinic[key];
            }
        });

        const headerPhone = document.querySelector('[data-clinic-phone]');
        headerPhone.textContent = clinic.phone || '';
        headerPhone.href = tel;
    } catch {
    }
}
