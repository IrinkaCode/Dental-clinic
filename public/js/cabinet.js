/**
 * Личный кабинет (cabinet.html): ближайший приём, запланированные визиты,
 * история посещений, контакты клиники, отмена записи.
 *
 * Данные вставляются через textContent, а не innerHTML:
 * так текст из базы не может выполниться как HTML-код (защита от XSS).
 */
'use strict';

document.addEventListener('user:ready', (event) => {
    showGreeting(event.detail);

    // После записи booking.js открывает cabinet.html?booked=1
    if (new URLSearchParams(location.search).has('booked')) {
        ui.showAlert(document.getElementById('page-success'), 'Вы записаны на приём. Ждём вас!');
        history.replaceState(null, '', 'cabinet.html');
    }
    loadClinic();
    loadAppointments();

    // Один обработчик на всю страницу для всех кнопок «Отменить»
    document.addEventListener('click', (e) => {
        const button = e.target.closest('[data-cancel-id]');
        if (button) {
            cancelAppointment(button);
        }
    });
});

function showGreeting(user) {
    // «Иванова Анна Сергеевна» → «Анна Сергеевна»
    const parts = user.full_name.split(' ');
    const name = parts.length > 1 ? parts.slice(1).join(' ') : user.full_name;
    document.getElementById('greeting').textContent = `Здравствуйте, ${name}`;
}

async function loadClinic() {
    try {
        const { clinic } = await api.get('clinic.php');
        document.querySelectorAll('[data-clinic]').forEach((el) => {
            const value = clinic[el.dataset.clinic] || '—';
            if (el.tagName === 'A') {
                el.href = (el.dataset.clinic === 'phone' ? 'tel:' + value.replace(/[^\d+]/g, '') : 'mailto:' + value);
            }
            el.textContent = value;
        });
    } catch (error) {
        showPageError(error.message);
    }
}

async function loadAppointments() {
    let data;
    try {
        data = await api.get('appointments.php');
    } catch (error) {
        showPageError(error.message);
        return;
    }

    document.getElementById('cancel-hours').textContent = data.cancel_min_hours;

    const [next, ...others] = data.upcoming;
    renderTicket(next);
    renderUpcoming(others);
    renderHistory(data.history);
}

/** Ближайший приём — «талон». */
function renderTicket(item) {
    const ticket = document.getElementById('next-visit');
    const empty = document.getElementById('no-visits');

    ticket.hidden = !item;
    empty.hidden = Boolean(item);
    if (!item) {
        return;
    }

    setField(ticket, 'date', `${fmt.dayMonth(item.start_at)}, ${fmt.weekday(item.start_at)}`);
    setField(ticket, 'time', fmt.time(item.start_at));
    setField(ticket, 'doctor', item.doctor_name);
    setField(ticket, 'service', item.service_name);
    setField(ticket, 'duration', fmt.duration(item.duration_min));
    setField(ticket, 'number', '№ ' + item.id);

    const button = ticket.querySelector('[data-cancel-id]');
    button.dataset.cancelId = item.id;
    button.hidden = !item.can_cancel;
    document.getElementById('ticket-phone-note').hidden = item.can_cancel;
}

/** Остальные запланированные визиты. */
function renderUpcoming(items) {
    const section = document.getElementById('upcoming-section');
    const list = document.getElementById('upcoming-list');
    const template = document.getElementById('visit-template');

    section.hidden = items.length === 0;
    list.replaceChildren();

    for (const item of items) {
        const li = template.content.firstElementChild.cloneNode(true);
        setField(li, 'date', fmt.dayMonth(item.start_at));
        setField(li, 'weekday-time', `${fmt.weekday(item.start_at)}, ${fmt.time(item.start_at)}`);
        setField(li, 'service', item.service_name);
        setField(li, 'doctor', `${item.doctor_name}, ${item.doctor_specialty.toLowerCase()}`);

        const button = li.querySelector('[data-cancel-id]');
        button.dataset.cancelId = item.id;
        button.hidden = !item.can_cancel;

        list.append(li);
    }
}

/** История посещений. */
function renderHistory(items) {
    const body = document.getElementById('history-body');
    const template = document.getElementById('history-template');
    document.getElementById('history-empty').hidden = items.length > 0;
    body.closest('.table-wrap').hidden = items.length === 0;
    body.replaceChildren();

    for (const item of items) {
        const tr = template.content.firstElementChild.cloneNode(true);
        setField(tr, 'date', fmt.dateTime(item.start_at));
        setField(tr, 'service', item.service_name);
        setField(tr, 'doctor', fmt.initials(item.doctor_name));
        setField(tr, 'price', item.status === 'cancelled' ? '—' : fmt.money(item.price));

        const status = tr.querySelector('[data-field="status"]');
        // Прошедший приём, который администратор ещё не отметил
        status.textContent = item.status === 'planned' ? 'Ожидает отметки' : (STATUS_LABELS[item.status] || item.status);
        status.classList.add('status--' + item.status);

        body.append(tr);
    }
}

async function cancelAppointment(button) {
    if (!confirm('Отменить запись на приём?')) {
        return;
    }
    ui.setBusy(button, true, 'Отменяем…');
    try {
        await api.post('appointment-cancel.php', { id: Number(button.dataset.cancelId) });
        await loadAppointments();
    } catch (error) {
        showPageError(error.message);
        ui.setBusy(button, false);
    }
}

/** Записывает текст в элемент с data-field="name" внутри root. */
function setField(root, name, value) {
    const el = root.querySelector(`[data-field="${name}"]`);
    if (el) {
        el.textContent = value;
    }
}

function showPageError(message) {
    ui.showAlert(document.getElementById('page-alert'), message);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
