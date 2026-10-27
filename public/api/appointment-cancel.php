<?php
/**
 * POST api/appointment-cancel.php — отмена своей записи пациентом.
 * Тело (JSON): id
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();
$user = requireAuth('patient');

$id = (int)(requestData()['id'] ?? 0);

// Запись ищется только среди записей текущего пользователя:
// чужую запись отменить нельзя, даже подставив её id.
$stmt = db()->prepare('SELECT * FROM appointments WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    jsonError('Запись не найдена.', 404);
}
if ($appointment['status'] !== 'planned' || $appointment['start_at'] < nowDb()) {
    jsonError('Эту запись уже нельзя отменить.', 409);
}
if (!canCancelAt($appointment['start_at'])) {
    $phone = clinicInfo()['phone'] ?? '';
    jsonError('Через сайт запись можно отменить не позднее чем за ' . CANCEL_MIN_HOURS
        . ' ч до приёма. Позвоните в клинику' . ($phone ? ": $phone." : '.'), 409);
}

db()->prepare(
    "UPDATE appointments SET status = 'cancelled', updated_at = ?
     WHERE id = ? AND status = 'planned'"
)->execute([date('Y-m-d H:i:s'), $id]);

auditLog($user['id'], 'appointment_cancel', 'appointments', $id);

jsonResponse(['ok' => true]);
