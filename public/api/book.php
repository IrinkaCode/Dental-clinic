<?php
/**
 * POST api/book.php — запись на приём.
 * Тело (JSON): service_id, doctor_id, date ('YYYY-MM-DD'), time ('HH:MM'), comment
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();
$user = requireAuth('patient');

$input     = requestData();
$serviceId = (int)($input['service_id'] ?? 0);
$doctorId  = (int)($input['doctor_id'] ?? 0);
$date      = inputString($input, 'date');
$time      = inputString($input, 'time');
$comment   = mb_substr(inputString($input, 'comment'), 0, 500);

$service = findActiveService($serviceId);
if (!$service) {
    jsonError('Выберите услугу.', 422);
}
if (!doctorProvidesService($doctorId, $serviceId)) {
    jsonError('Выберите врача.', 422);
}
if (countActiveAppointments($user['id']) >= MAX_ACTIVE_APPOINTMENTS) {
    jsonError('У вас уже ' . MAX_ACTIVE_APPOINTMENTS
        . ' запланированных приёмов. Новая запись станет доступна после одного из них.', 409);
}

// Время проверяется той же функцией, что строит список свободных слотов:
// нельзя записаться на время вне расписания, в прошлое или на занятое.
if (!isSlotFree($doctorId, $service['duration_min'], $user['id'], $date, $time)) {
    jsonError('Это время уже занято или недоступно. Выберите другое.', 409, [], 'slot');
}

$start = "$date $time";
$end   = date('Y-m-d H:i', strtotime($start) + $service['duration_min'] * 60);

try {
    db()->prepare(
        'INSERT INTO appointments (user_id, doctor_id, service_id, start_at, end_at, comment, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$user['id'], $doctorId, $serviceId, $start, $end, $comment !== '' ? $comment : null, date('Y-m-d H:i:s')]);
} catch (PDOException $e) {
    // Кто-то успел занять это время между проверкой и сохранением — сработал триггер БД
    if (isSlotTakenError($e)) {
        jsonError('Это время только что заняли. Выберите другое.', 409, [], 'slot');
    }
    throw $e;
}

$id = (int)db()->lastInsertId();
auditLog($user['id'], 'appointment_create', 'appointments', $id);

jsonResponse(['id' => $id], 201);
