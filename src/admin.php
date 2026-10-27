<?php
/**
 * Общие функции панели администратора (обработчики в public/api/admin/).
 */

declare(strict_types=1);

require_once __DIR__ . '/booking.php';

const APPOINTMENT_STATUSES = ['planned', 'completed', 'cancelled'];

/**
 * Первая строка каждого обработчика админки после bootstrap:
 * только администратор, только GET (чтение) и POST (изменения, с CSRF-токеном).
 */
function adminStart(): array
{
    $admin = requireAuth('admin');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET, POST');
        jsonError('Метод не поддерживается.', 405);
    }
    return $admin;
}

function isPost(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function inputInt(array $input, string $key): int
{
    $value = $input[$key] ?? 0;
    return is_numeric($value) ? (int)$value : 0;
}

function inputBool(array $input, string $key): bool
{
    return in_array($input[$key] ?? false, [true, 1, '1', 'on', 'true'], true);
}

function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidTime(string $time): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
}

/** Есть ли строка $query в одном из значений (без учёта регистра; телефоны — по цифрам). */
function textMatches(string $query, array $values): bool
{
    $query = mb_strtolower(trim($query));
    if ($query === '') {
        return true;
    }
    $queryDigits = preg_replace('/\D+/', '', $query);

    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }
        if (mb_stripos($value, $query) !== false) {
            return true;
        }
        if (strlen($queryDigits) >= 3 && str_contains(preg_replace('/\D+/', '', $value), $queryDigits)) {
            return true;
        }
    }
    return false;
}

/** Сколько активных администраторов, кроме указанного пользователя. */
function otherActiveAdmins(int $exceptId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $stmt->execute([$exceptId]);
    return (int)$stmt->fetchColumn();
}

/** Помещается ли приём [$start, $end) в рабочие часы врача в этот день недели. */
function fitsSchedule(int $doctorId, string $start, string $end): bool
{
    $stmt = db()->prepare('SELECT time_start, time_end FROM doctor_schedule WHERE doctor_id = ? AND weekday = ?');
    $stmt->execute([$doctorId, (int)date('N', strtotime($start))]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $date = substr($start, 0, 10);
    return substr($end, 0, 10) === $date
        && $start >= "$date {$row['time_start']}"
        && $end <= "$date {$row['time_end']}";
}

/**
 * Проверка данных записи, которую создаёт или изменяет администратор.
 * В отличие от записи пациентом, можно указать прошедшее время
 * (например, внести визит задним числом), но не время вне расписания врача.
 */
function validateAppointment(array $input): array
{
    $errors = [];

    $serviceId = inputInt($input, 'service_id');
    $doctorId  = inputInt($input, 'doctor_id');
    $date      = inputString($input, 'date');
    $time      = inputString($input, 'time');
    $status    = inputString($input, 'status') ?: 'planned';
    $comment   = mb_substr(inputString($input, 'comment'), 0, 500);

    $service = findActiveService($serviceId);
    if (!$service) {
        $errors['service_id'] = 'Выберите услугу.';
    } elseif (!doctorProvidesService($doctorId, $serviceId)) {
        $errors['doctor_id'] = 'Выберите врача, который оказывает эту услугу.';
    }
    if (!isValidDate($date)) {
        $errors['date'] = 'Укажите дату.';
    }
    if (!isValidTime($time)) {
        $errors['time'] = 'Укажите время в формате ЧЧ:ММ.';
    }
    if (!in_array($status, APPOINTMENT_STATUSES, true)) {
        $errors['status'] = 'Неизвестный статус.';
    }

    $start = "$date $time";
    $end   = '';
    if (!$errors) {
        $end = date('Y-m-d H:i', strtotime($start) + $service['duration_min'] * 60);
        if (!fitsSchedule($doctorId, $start, $end)) {
            $errors['time'] = 'Это время не входит в рабочие часы врача (с учётом длительности услуги).';
        }
    }

    return [[
        'service_id' => $serviceId,
        'doctor_id'  => $doctorId,
        'start_at'   => $start,
        'end_at'     => $end,
        'status'     => $status,
        'comment'    => $comment !== '' ? $comment : null,
    ], $errors];
}

/** Нарушение UNIQUE в SQLite (например, услуга с таким названием уже есть). */
function isUniqueViolation(PDOException $e): bool
{
    return str_contains($e->getMessage(), 'UNIQUE constraint failed');
}
