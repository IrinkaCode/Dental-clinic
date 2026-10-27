<?php
/**
 * Запись на приём: доступные услуги, врачи и расчёт свободного времени.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/appointments.php';

const SLOT_STEP_MIN           = 30;   // шаг сетки времени, минут
const BOOKING_DAYS_AHEAD      = 14;   // на сколько дней вперёд можно записаться
const BOOKING_MIN_LEAD_MIN    = 60;   // не раньше чем через час от текущего времени
const MAX_ACTIVE_APPOINTMENTS = 5;    // сколько будущих записей может быть у пациента одновременно

/** Услуги, которые можно забронировать (активные и хотя бы с одним активным врачом). */
function activeServices(): array
{
    $rows = db()->query(
        'SELECT s.id, s.name, s.description, s.price, s.duration_min
         FROM services s
         WHERE s.is_active = 1
           AND EXISTS (SELECT 1 FROM doctor_services ds
                       JOIN doctors d ON d.id = ds.doctor_id
                       WHERE ds.service_id = s.id AND d.is_active = 1)
         ORDER BY s.id'
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['id']           = (int)$row['id'];
        $row['price']        = (int)$row['price'];
        $row['duration_min'] = (int)$row['duration_min'];
    }
    return $rows;
}

function findActiveService(int $id): ?array
{
    foreach (activeServices() as $service) {
        if ($service['id'] === $id) {
            return $service;
        }
    }
    return null;
}

/** Активные врачи, оказывающие услугу. */
function doctorsForService(int $serviceId): array
{
    $stmt = db()->prepare(
        'SELECT d.id, d.full_name, d.specialty, d.description
         FROM doctors d
         JOIN doctor_services ds ON ds.doctor_id = d.id
         WHERE ds.service_id = ? AND d.is_active = 1
         ORDER BY d.full_name'
    );
    $stmt->execute([$serviceId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
    }
    return $rows;
}

/** Все работающие врачи со списком названий доступных услуг. */
function activeDoctors(): array
{
    $doctors = db()->query(
        'SELECT id, full_name, specialty, description FROM doctors WHERE is_active = 1 ORDER BY full_name'
    )->fetchAll();

    $stmt = db()->prepare(
        'SELECT s.name FROM services s
         JOIN doctor_services ds ON ds.service_id = s.id
         WHERE ds.doctor_id = ? AND s.is_active = 1
         ORDER BY s.name'
    );
    foreach ($doctors as &$doctor) {
        $doctor['id'] = (int)$doctor['id'];
        $stmt->execute([$doctor['id']]);
        $doctor['services'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return $doctors;
}

function doctorProvidesService(int $doctorId, int $serviceId): bool
{
    foreach (doctorsForService($serviceId) as $doctor) {
        if ($doctor['id'] === $doctorId) {
            return true;
        }
    }
    return false;
}

/**
 * Дни на BOOKING_DAYS_AHEAD вперёд со временем приёма.
 * Для каждого дня: date, weekday (1 = пн), times: [{time: '09:00', free: true}, ...]
 *
 * Время занято, если пересекается с запланированным приёмом
 * этого врача или с другой записью этого же пациента.
 */
function availableDays(int $doctorId, int $durationMin, int $userId): array
{
    // Расписание врача: день недели => [начало, конец]
    $stmt = db()->prepare('SELECT weekday, time_start, time_end FROM doctor_schedule WHERE doctor_id = ?');
    $stmt->execute([$doctorId]);
    $schedule = [];
    foreach ($stmt->fetchAll() as $row) {
        $schedule[(int)$row['weekday']] = [$row['time_start'], $row['time_end']];
    }

    $firstDay = strtotime('today');
    $lastDay  = strtotime('+' . BOOKING_DAYS_AHEAD . ' days', $firstDay);

    // Занятые интервалы за весь период одним запросом
    $stmt = db()->prepare(
        "SELECT start_at, end_at FROM appointments
         WHERE status = 'planned' AND (doctor_id = ? OR user_id = ?)
           AND start_at < ? AND end_at > ?"
    );
    $stmt->execute([$doctorId, $userId, date('Y-m-d 00:00', $lastDay), date('Y-m-d 00:00', $firstDay)]);
    $busy = $stmt->fetchAll();

    $earliest = date('Y-m-d H:i', time() + BOOKING_MIN_LEAD_MIN * 60);
    $days = [];

    for ($i = 0; $i < BOOKING_DAYS_AHEAD; $i++) {
        $dayTs   = strtotime("+$i days", $firstDay);
        $date    = date('Y-m-d', $dayTs);
        $weekday = (int)date('N', $dayTs);
        $times   = [];

        if (isset($schedule[$weekday])) {
            [$workStart, $workEnd] = $schedule[$weekday];
            $slot = strtotime("$date $workStart");
            $end  = strtotime("$date $workEnd");

            while ($slot + $durationMin * 60 <= $end) {
                $start  = date('Y-m-d H:i', $slot);
                $finish = date('Y-m-d H:i', $slot + $durationMin * 60);

                if ($start >= $earliest) {
                    $times[] = ['time' => date('H:i', $slot), 'free' => !overlapsAny($busy, $start, $finish)];
                }
                $slot += SLOT_STEP_MIN * 60;
            }
        }

        $days[] = ['date' => $date, 'weekday' => $weekday, 'times' => $times];
    }

    return $days;
}

/** Пересекается ли интервал [$start, $end) хотя бы с одним из занятых. */
function overlapsAny(array $busy, string $start, string $end): bool
{
    foreach ($busy as $interval) {
        if ($start < $interval['end_at'] && $end > $interval['start_at']) {
            return true;
        }
    }
    return false;
}

/** Свободно ли время $date $time для записи (проверка той же логикой, что и показ слотов). */
function isSlotFree(int $doctorId, int $durationMin, int $userId, string $date, string $time): bool
{
    foreach (availableDays($doctorId, $durationMin, $userId) as $day) {
        if ($day['date'] !== $date) {
            continue;
        }
        foreach ($day['times'] as $slot) {
            if ($slot['time'] === $time) {
                return $slot['free'];
            }
        }
    }
    return false;
}

/** Количество будущих запланированных приёмов пациента. */
function countActiveAppointments(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND status = 'planned' AND start_at >= ?");
    $stmt->execute([$userId, nowDb()]);
    return (int)$stmt->fetchColumn();
}
