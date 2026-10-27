<?php
/**
 * Расписание врача (только администратор).
 *
 * GET  api/admin/schedule.php?doctor_id=1   — рабочие дни врача
 * POST api/admin/schedule.php   {doctor_id, days: [{weekday: 1, time_start: '09:00', time_end: '18:00'}, ...]}
 *      Передаются только рабочие дни; расписание заменяется целиком.
 *
 * Уже сделанные записи при смене расписания не удаляются.
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();
$input = isPost() ? requestData() : $_GET;

$doctorId = inputInt($input, 'doctor_id');
$stmt = db()->prepare('SELECT id FROM doctors WHERE id = ?');
$stmt->execute([$doctorId]);
$stmt->fetch() ?: jsonError('Врач не найден.', 404);

if (!isPost()) {
    $stmt = db()->prepare('SELECT weekday, time_start, time_end FROM doctor_schedule WHERE doctor_id = ? ORDER BY weekday');
    $stmt->execute([$doctorId]);
    $days = $stmt->fetchAll();
    foreach ($days as &$day) {
        $day['weekday'] = (int)$day['weekday'];
    }
    jsonResponse(['days' => $days]);
}

$days   = [];
$errors = [];
foreach ((array)($input['days'] ?? []) as $day) {
    $weekday = inputInt((array)$day, 'weekday');
    $start   = inputString((array)$day, 'time_start');
    $end     = inputString((array)$day, 'time_end');

    if ($weekday < 1 || $weekday > 7 || isset($days[$weekday])) {
        jsonError('Некорректный день недели.', 422);
    }
    if (!isValidTime($start) || !isValidTime($end) || $start >= $end) {
        $errors["day_$weekday"] = 'Начало работы должно быть раньше окончания.';
    }
    $days[$weekday] = [$start, $end];
}
if ($errors) {
    jsonError('Проверьте часы работы.', 422, $errors);
}

db()->beginTransaction();
db()->prepare('DELETE FROM doctor_schedule WHERE doctor_id = ?')->execute([$doctorId]);
$insert = db()->prepare('INSERT INTO doctor_schedule (doctor_id, weekday, time_start, time_end) VALUES (?, ?, ?, ?)');
foreach ($days as $weekday => [$start, $end]) {
    $insert->execute([$doctorId, $weekday, $start, $end]);
}
db()->commit();

auditLog($admin['id'], 'admin_schedule_update', 'doctors', $doctorId);
jsonResponse(['ok' => true]);
