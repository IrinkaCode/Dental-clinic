-- =====================================================================
--  Стоматологическая клиника — схема базы данных SQLite
--  Персональные данные пользователей хранятся только в зашифрованном
--  виде, пароль — только в виде хеша (password_hash).
-- =====================================================================

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------
--  Пользователи (пациенты и администраторы)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email_hash      TEXT    NOT NULL UNIQUE,   -- HMAC-SHA256 от email: поиск при входе и проверка уникальности
    email_enc       TEXT    NOT NULL,          -- email в зашифрованном виде
    password_hash   TEXT    NOT NULL,          -- хеш пароля (Argon2id или bcrypt)
    full_name_enc   TEXT    NOT NULL,          -- ФИО в зашифрованном виде
    phone_enc       TEXT,                      -- телефон в зашифрованном виде
    birth_date_enc  TEXT,                      -- дата рождения в зашифрованном виде
    role            TEXT    NOT NULL DEFAULT 'patient'
                            CHECK (role IN ('patient', 'admin')),
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    failed_logins   INTEGER NOT NULL DEFAULT 0,  -- счётчик неудачных попыток входа
    locked_until    TEXT,                        -- временная блокировка после неудачных попыток
    created_at      TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at      TEXT
);

-- ---------------------------------------------------------------------
--  Врачи
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS doctors (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name    TEXT    NOT NULL,
    specialty    TEXT    NOT NULL,
    description  TEXT,
    photo        TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))
);

-- ---------------------------------------------------------------------
--  Услуги
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL UNIQUE,
    description   TEXT,
    price         INTEGER NOT NULL CHECK (price >= 0),
    duration_min  INTEGER NOT NULL CHECK (duration_min > 0 AND duration_min % 15 = 0),
    is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))
);

-- ---------------------------------------------------------------------
--  Какие услуги оказывает каждый врач (связь «многие ко многим»)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS doctor_services (
    doctor_id   INTEGER NOT NULL REFERENCES doctors(id)  ON DELETE CASCADE,
    service_id  INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    PRIMARY KEY (doctor_id, service_id)
);

-- ---------------------------------------------------------------------
--  Расписание врачей: один интервал работы на день недели
--  weekday: 1 = понедельник ... 7 = воскресенье
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS doctor_schedule (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    doctor_id   INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
    weekday     INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    time_start  TEXT    NOT NULL CHECK (time_start GLOB '[0-2][0-9]:[0-5][0-9]'),
    time_end    TEXT    NOT NULL CHECK (time_end   GLOB '[0-2][0-9]:[0-5][0-9]'),
    CHECK (time_start < time_end),
    UNIQUE (doctor_id, weekday)
);

-- ---------------------------------------------------------------------
--  Записи на приём (и будущие, и история посещений)
--  Даты хранятся строкой 'YYYY-MM-DD HH:MM'
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    doctor_id   INTEGER NOT NULL REFERENCES doctors(id)  ON DELETE RESTRICT,
    service_id  INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
    start_at    TEXT    NOT NULL,
    end_at      TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'planned'
                        CHECK (status IN ('planned', 'completed', 'cancelled')),
    comment     TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at  TEXT,
    CHECK (start_at < end_at)
);

CREATE INDEX IF NOT EXISTS idx_appointments_user   ON appointments (user_id, start_at);
CREATE INDEX IF NOT EXISTS idx_appointments_doctor ON appointments (doctor_id, start_at);

-- Защита от двойной записи на уровне БД: у врача и у пациента не может быть
-- двух запланированных приёмов, пересекающихся по времени.
-- При нарушении SQLite вернёт ошибку с текстом SLOT_TAKEN.
CREATE TRIGGER IF NOT EXISTS trg_appointments_no_overlap_insert
BEFORE INSERT ON appointments
WHEN NEW.status = 'planned'
BEGIN
    SELECT RAISE(ABORT, 'SLOT_TAKEN')
    WHERE EXISTS (
        SELECT 1 FROM appointments
        WHERE status = 'planned'
          AND (doctor_id = NEW.doctor_id OR user_id = NEW.user_id)
          AND start_at < NEW.end_at
          AND end_at   > NEW.start_at
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_appointments_no_overlap_update
BEFORE UPDATE OF start_at, end_at, doctor_id, user_id, status ON appointments
WHEN NEW.status = 'planned'
BEGIN
    SELECT RAISE(ABORT, 'SLOT_TAKEN')
    WHERE EXISTS (
        SELECT 1 FROM appointments
        WHERE id <> NEW.id
          AND status = 'planned'
          AND (doctor_id = NEW.doctor_id OR user_id = NEW.user_id)
          AND start_at < NEW.end_at
          AND end_at   > NEW.start_at
    );
END;

-- ---------------------------------------------------------------------
--  Контактная информация клиники (ключ — значение)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clinic_info (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
);

-- ---------------------------------------------------------------------
--  Журнал действий (кто, что и когда изменил)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action      TEXT    NOT NULL,
    table_name  TEXT,
    record_id   INTEGER,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log (created_at);

-- =====================================================================
--  Тестовые данные
-- =====================================================================

INSERT OR IGNORE INTO services (id, name, description, price, duration_min) VALUES
    (1, 'Консультация стоматолога',      'Осмотр, составление плана лечения',          800,  30),
    (2, 'Профессиональная гигиена',      'Ультразвуковая чистка, Air Flow, полировка', 4500, 60),
    (3, 'Лечение кариеса',               'Пломба из светоотверждаемого материала',     5500, 60),
    (4, 'Лечение пульпита',              'Лечение каналов одного зуба',                9000, 90),
    (5, 'Удаление зуба',                 'Простое удаление с анестезией',              3000, 30),
    (6, 'Консультация ортодонта',        'Диагностика прикуса, подбор системы',        1200, 30);

INSERT OR IGNORE INTO doctors (id, full_name, specialty, description) VALUES
    (1, 'Смирнова Елена Викторовна', 'Стоматолог-терапевт', 'Стаж 12 лет. Лечение кариеса и пульпита.'),
    (2, 'Козлов Андрей Сергеевич',   'Стоматолог-хирург',   'Стаж 9 лет. Удаление зубов любой сложности.'),
    (3, 'Петрова Мария Игоревна',    'Ортодонт',            'Стаж 7 лет. Брекеты и элайнеры.');

INSERT OR IGNORE INTO doctor_services (doctor_id, service_id) VALUES
    (1, 1), (1, 2), (1, 3), (1, 4),
    (2, 1), (2, 5),
    (3, 6);

INSERT OR IGNORE INTO doctor_schedule (doctor_id, weekday, time_start, time_end) VALUES
    (1, 1, '09:00', '18:00'), (1, 2, '09:00', '18:00'), (1, 3, '09:00', '18:00'),
    (1, 4, '09:00', '18:00'), (1, 5, '09:00', '15:00'),
    (2, 1, '10:00', '19:00'), (2, 3, '10:00', '19:00'), (2, 5, '10:00', '19:00'),
    (3, 2, '12:00', '20:00'), (3, 4, '12:00', '20:00'), (3, 6, '10:00', '15:00');

INSERT OR IGNORE INTO clinic_info (key, value) VALUES
    ('name',          'Стоматологическая клиника «АС Дент»'),
    ('address',       'г. Калининград, ул. Юбилейная, д. 12'),
    ('phone',         '+7 (911) 451-45-12'),
    ('email',         'info@asdent-clinic.ru'),
    ('working_hours', 'Пн–Пт 09:00–20:00, Сб 10:00–15:00, Вс — выходной');
