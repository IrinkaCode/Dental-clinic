/**
 * Запись на приём
 */
'use strict';

const state = {
    services: [],
    doctors: [],
    days: [],
    service: null,   // выбранная услуга (объект)
    doctor: null,    // выбранный врач (объект)
    date: null,      // 'YYYY-MM-DD'
    time: null,      // 'HH:MM'
};

let form;

document.addEventListener('user:ready', async () => {
    form = document.getElementById('booking-form');
    form.addEventListener('change', onChange);
    form.addEventListener('submit', onSubmit);

    try {
        const { services } = await api.get('services.php');
        state.services = services;
        renderServices();
    } catch (error) {
        showError(error.message);
    }
});

/** Один обработчик на все переключатели формы. */
function onChange(event) {
    const { name, value } = event.target;
    hideError();

    if (name === 'service_id') {
        selectService(Number(value));
    } else if (name === 'doctor_id') {
        selectDoctor(Number(value));
    } else if (name === 'date') {
        selectDate(value);
    } else if (name === 'time') {
        state.time = value;
        updateSummary();
    }
}

// ---------------------------------------------------------------------
//  Выбор
// ---------------------------------------------------------------------

async function selectService(id) {
    state.service = state.services.find((s) => s.id === id) || null;
    state.doctor = null;
    resetDateTime('Сначала выберите врача.');
    document.getElementById('doctor-list').replaceChildren();
    setHint('doctor-hint', 'Загружаем врачей…');
    updateSummary();

    try {
        const { doctors } = await api.get('doctors.php?service_id=' + id);
        if (state.service?.id !== id) {
            return;   // пока шёл запрос, пользователь выбрал другую услугу
        }
        state.doctors = doctors;
        renderDoctors();

        // Если врач один — выбираем его сразу
        if (doctors.length === 1) {
            document.querySelector('#doctor-list input').checked = true;
            selectDoctor(doctors[0].id);
        }
    } catch (error) {
        showError(error.message);
    }
}

async function selectDoctor(id) {
    state.doctor = state.doctors.find((d) => d.id === id) || null;
    resetDateTime('Загружаем расписание…');
    updateSummary();

    try {
        const { days } = await api.get(`slots.php?doctor_id=${id}&service_id=${state.service.id}`);
        if (state.doctor?.id !== id) {
            return;
        }
        state.days = days;
        renderDates();

        // Сразу выбираем первый день, где есть свободное время
        const firstFree = document.querySelector('#date-list input:not(:disabled)');
        if (firstFree) {
            firstFree.checked = true;
            selectDate(firstFree.value);
        }
    } catch (error) {
        showError(error.message);
    }
}

function selectDate(date) {
    state.date = date;
    state.time = null;
    renderTimes();
    updateSummary();
}

function resetDateTime(hint) {
    state.days = [];
    state.date = null;
    state.time = null;
    document.getElementById('date-list').replaceChildren();
    document.getElementById('time-list').replaceChildren();
    setHint('date-hint', hint);
}

// ---------------------------------------------------------------------
//  Отрисовка
// ---------------------------------------------------------------------

function renderServices() {
    const list = document.getElementById('service-list');
    list.replaceChildren();

    for (const service of state.services) {
        list.append(makeChoice('service_id', service.id, service.name,
            `${service.description || ''}, ${fmt.duration(service.duration_min)}`.replace(/^, /, ''),
            fmt.money(service.price)));
    }
    setHint('service-hint', state.services.length ? '' : 'Сейчас нет доступных услуг. Позвоните в клинику.');
}

function renderDoctors() {
    const list = document.getElementById('doctor-list');
    list.replaceChildren();

    for (const doctor of state.doctors) {
        list.append(makeChoice('doctor_id', doctor.id, doctor.full_name, doctor.specialty, ''));
    }
    setHint('doctor-hint', state.doctors.length ? '' : 'Нет врачей для этой услуги.');
}

function renderDates() {
    const list = document.getElementById('date-list');
    list.replaceChildren();

    for (const day of state.days) {
        const hasFree = day.times.some((t) => t.free);
        const value = day.date + ' 00:00';
        list.append(makeSlot('date', day.date, fmt.dayShort(value), fmt.weekday(value), !hasFree));
    }

    const anyFree = state.days.some((day) => day.times.some((t) => t.free));
    setHint('date-hint', anyFree ? ''
        : 'В ближайшие две недели у врача нет свободного времени. Выберите другого врача или позвоните в клинику.');
}

function renderTimes() {
    const list = document.getElementById('time-list');
    list.replaceChildren();

    const day = state.days.find((d) => d.date === state.date);
    if (!day) {
        return;
    }
    for (const slot of day.times) {
        list.append(makeSlot('time', slot.time, slot.time, '', !slot.free));
    }
}

/** Карточка выбора (услуга или врач) */
function makeChoice(name, value, title, meta, price) {
    const label = document.getElementById('choice-template').content.firstElementChild.cloneNode(true);
    const input = label.querySelector('input');
    input.name = name;
    input.value = value;
    label.querySelector('[data-field="title"]').textContent = title;
    label.querySelector('[data-field="meta"]').textContent = meta;
    label.querySelector('[data-field="price"]').textContent = price;
    return label;
}

/** Кнопка даты или времени */
function makeSlot(name, value, text, sub, disabled) {
    const label = document.getElementById('slot-template').content.firstElementChild.cloneNode(true);
    const input = label.querySelector('input');
    input.name = name;
    input.value = value;
    input.disabled = disabled;
    label.querySelector('[data-field="label"]').textContent = text;
    label.querySelector('[data-field="sub"]').textContent = sub;
    return label;
}

function updateSummary() {
    const { service, doctor, date, time } = state;

    document.getElementById('summary-service').textContent = service ? service.name : '—';
    document.getElementById('summary-doctor').textContent = doctor ? doctor.full_name : '—';
    document.getElementById('summary-price').textContent = service ? fmt.money(service.price) : '—';

    let when = '—';
    if (date && time) {
        const start = date + ' ' + time;
        const end = fmt.parse(start);
        end.setMinutes(end.getMinutes() + service.duration_min);
        const endTime = end.toTimeString().slice(0, 5);
        when = `${fmt.dayMonth(start)}, ${fmt.weekday(start)}, ${time}–${endTime}`;
    }
    document.getElementById('summary-datetime').textContent = when;

    document.getElementById('booking-submit').disabled = !(service && doctor && date && time);
}

// ---------------------------------------------------------------------
//  Отправка
// ---------------------------------------------------------------------

async function onSubmit(event) {
    event.preventDefault();
    const button = document.getElementById('booking-submit');
    hideError();
    ui.setBusy(button, true, 'Записываем…');

    try {
        await api.post('book.php', {
            service_id: state.service.id,
            doctor_id: state.doctor.id,
            date: state.date,
            time: state.time,
            comment: form.elements.comment.value,
        });
        location.replace('cabinet.html?booked=1');
    } catch (error) {
        showError(error.message);
        ui.setBusy(button, false);

        // Время заняли — обновляем расписание врача
        if (error.code === 'slot') {
            await selectDoctor(state.doctor.id);
        }
    }
}

// ---------------------------------------------------------------------
//  Подсказки и ошибки
// ---------------------------------------------------------------------

function setHint(id, text) {
    const el = document.getElementById(id);
    el.textContent = text;
    el.hidden = !text;
}

function showError(message) {
    ui.showAlert(document.getElementById('booking-error'), message);
}

function hideError() {
    document.getElementById('booking-error').hidden = true;
}
