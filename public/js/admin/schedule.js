/**
 * Раздел «Расписание»: рабочие дни и часы каждого врача.
 */
'use strict';

const WEEKDAYS = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

adminSections.schedule = {
    init() {
        const body = document.getElementById('schedule-body');

        // Семь строк: флажок «рабочий день» и время начала и окончания
        WEEKDAYS.forEach((name, index) => {
            const weekday = index + 1;
            const tr = document.createElement('tr');
            tr.dataset.weekday = weekday;
            tr.innerHTML = `
                <td>${name}</td>
                <td><input type="checkbox" name="works_${weekday}" aria-label="${name}: рабочий день"></td>
                <td><input type="time" name="start_${weekday}" value="09:00" step="900" aria-label="${name}: начало"></td>
                <td><input type="time" name="end_${weekday}" value="18:00" step="900" aria-label="${name}: окончание"></td>`;
            body.append(tr);
        });

        const form = document.getElementById('schedule-form');
        form.addEventListener('change', (event) => {
            if (event.target.name === 'doctor_id') {
                this.loadDays();
            } else if (event.target.type === 'checkbox') {
                this.updateRow(event.target.closest('tr'));
            }
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.save();
        });
    },

    async load() {
        const { doctors } = await admin.get('doctors.php');
        admin.fillSelect(document.getElementById('schedule-doctor'), doctors.map((d) => ({
            value: d.id,
            text: d.full_name + (d.is_active ? '' : ' (не принимает)'),
        })));
        if (doctors.length) {
            await this.loadDays();
        }
    },

    async loadDays() {
        const doctorId = document.getElementById('schedule-doctor').value;
        const { days } = await admin.get('schedule.php', { doctor_id: doctorId });
        const byWeekday = Object.fromEntries(days.map((d) => [d.weekday, d]));

        document.getElementById('schedule-error').hidden = true;
        document.querySelectorAll('#schedule-body tr').forEach((tr) => {
            const day = byWeekday[tr.dataset.weekday];
            const [works, start, end] = tr.querySelectorAll('input');
            works.checked = Boolean(day);
            start.value = day ? day.time_start : '09:00';
            end.value = day ? day.time_end : '18:00';
            this.updateRow(tr);
        });
    },

    /** Время в нерабочие дни недоступно для ввода. */
    updateRow(tr) {
        const [works, start, end] = tr.querySelectorAll('input');
        start.disabled = !works.checked;
        end.disabled = !works.checked;
        tr.classList.toggle('is-off', !works.checked);
    },

    async save() {
        const days = [];
        document.querySelectorAll('#schedule-body tr').forEach((tr) => {
            const [works, start, end] = tr.querySelectorAll('input');
            if (works.checked) {
                days.push({ weekday: Number(tr.dataset.weekday), time_start: start.value, time_end: end.value });
            }
        });

        const errorBox = document.getElementById('schedule-error');
        errorBox.hidden = true;
        try {
            await admin.post('schedule.php', { doctor_id: document.getElementById('schedule-doctor').value, days });
            admin.notify('Расписание сохранено.');
        } catch (error) {
            const details = Object.entries(error.fields || {})
                .map(([key, text]) => `${WEEKDAYS[Number(key.split('_')[1]) - 1]}: ${text}`);
            ui.showAlert(errorBox, [error.message, ...details].join(' '));
        }
    },
};
