/**
 * Раздел «Врачи»: список, добавление, изменение, оказываемые услуги
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

        document.getElementById('doctor-photo-choose').addEventListener('click', () => {
            document.getElementById('doctor-photo-file').click();
        });
        document.getElementById('doctor-photo-file').addEventListener('change', (e) => this.onPhotoSelected(e));
        document.getElementById('doctor-photo-remove').addEventListener('click', () => this.removePhoto());
    },

    async load() {
        const { doctors, services } = await admin.get('doctors.php');
        this.services = services;
        const names = Object.fromEntries(services.map((s) => [s.id, s.name]));

        admin.fillTable(document.getElementById('doctors-body'), doctors, (doctor) => {
            const tr = document.createElement('tr');
            const photoCell = document.createElement('td');
            photoCell.append(ui.avatar(doctor.full_name, doctor.photo, 'avatar-cell'));
            tr.append(
                photoCell,
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
            // defaultChecked: при открытии окно сбрасывает форму (form.reset),
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

        // Блок фото
        this.currentDoctorId = doctor ? doctor.id : null;
        this.currentDoctorName = doctor ? doctor.full_name : '';
        this.renderPhotoPreview(this.currentDoctorName, doctor ? doctor.photo : null);
        document.getElementById('doctor-photo-choose').disabled = !doctor;
        document.getElementById('doctor-photo-remove').hidden = !(doctor && doctor.photo);
        document.getElementById('doctor-photo-hint').hidden = Boolean(doctor);
    },

    renderPhotoPreview(fullName, photoUrl) {
        const el = document.getElementById('doctor-photo-preview');
        el.replaceChildren();
        if (photoUrl) {
            const img = document.createElement('img');
            img.src = photoUrl;
            img.alt = '';
            el.append(img);
        } else {
            el.textContent = ui.avatarInitials(fullName);
        }
    },

    /** Обрезает фото в квадрат по центру и сжимает его перед отправкой на сервер. */
    resizePhoto(file, size = 480) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(url);
                const side = Math.min(img.naturalWidth, img.naturalHeight);
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                canvas.getContext('2d').drawImage(
                    img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, size, size,
                );
                canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('canvas'))), 'image/jpeg', 0.85);
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
            img.src = url;
        });
    },

    async onPhotoSelected(event) {
        const file = event.target.files[0];
        event.target.value = '';   // чтобы можно было выбрать тот же файл ещё раз
        if (!file || !this.currentDoctorId) {
            return;
        }
        if (!file.type.startsWith('image/')) {
            admin.notify('Выберите файл изображения: JPEG, PNG или WEBP.', 'error');
            return;
        }
        if (file.size > 15 * 1024 * 1024) {
            admin.notify('Файл слишком большой. Выберите фото меньшего размера.', 'error');
            return;
        }

        const button = document.getElementById('doctor-photo-choose');
        ui.setBusy(button, true, 'Загружаем…');
        try {
            const blob = await this.resizePhoto(file);
            const form = new FormData();
            form.append('doctor_id', this.currentDoctorId);
            form.append('photo', blob, 'photo.jpg');
            const { photo } = await admin.upload('doctor-photo.php', form);

            this.renderPhotoPreview(this.currentDoctorName, photo);
            document.getElementById('doctor-photo-remove').hidden = false;
            admin.notify('Фото сохранено.');
            await this.load();
        } catch (error) {
            admin.notify(error.message || 'Не удалось обработать изображение. Выберите другой файл.', 'error');
        } finally {
            ui.setBusy(button, false);
        }
    },

    async removePhoto() {
        if (!this.currentDoctorId || !confirm('Удалить фото врача?')) {
            return;
        }
        try {
            const form = new FormData();
            form.append('doctor_id', this.currentDoctorId);
            form.append('remove', '1');
            await admin.upload('doctor-photo.php', form);

            this.renderPhotoPreview(this.currentDoctorName, null);
            document.getElementById('doctor-photo-remove').hidden = true;
            admin.notify('Фото удалено.');
            await this.load();
        } catch (error) {
            admin.notify(error.message, 'error');
        }
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
