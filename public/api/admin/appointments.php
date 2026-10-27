<?php
/**
 * Записи на приём (только администратор).
 *
 * GET  api/admin/appointments.php?date_from=&date_to=&doctor_id=&status=&q=
 *      status: planned | completed | cancelled | overdue (прошедшие, но не отмеченные)
 * POST api/admin/appointments.php  {action: 'create' | 'update' | 'status' | 'delete', ...}
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();

if (!isPost()) {
    $where  = [];
    $params = [];

    $dateFrom = (string)($_GET['date_from'] ?? '');
    $dateTo   = (string)($_GET['date_to'] ?? '');
    $doctorId = (int)($_GET['doctor_id'] ?? 0);
    $status   = (string)($_GET['status'] ?? '');

    if (isValidDate($dateFrom)) {
        $where[]  = 'a.start_at >= ?';
        $params[] = "$dateFrom 00:00";
    }
    if (isValidDate($dateTo)) {
        $where[]  = 'a.start_at <= ?';
        $params[] = "$dateTo 23:59";
    }
    if ($doctorId) {
        $where[]  = 'a.doctor_id = ?';
        $params[] = $doctorId;
    }
    if ($status === 'overdue') {
        $where[]  = "a.status = 'planned' AND a.start_at < ?";
        $params[] = nowDb();
    } elseif (in_array($status, APPOINTMENT_STATUSES, true)) {
        $where[]  = 'a.status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT a.id, a.user_id, a.doctor_id, a.service_id, a.start_at, a.end_at, a.status, a.comment,
                   u.full_name_enc, u.phone_enc,
                   d.full_name AS doctor_name, s.name AS service_name, s.price, s.duration_min
            FROM appointments a
            JOIN users u    ON u.id = a.user_id
            JOIN doctors d  ON d.id = a.doctor_id
            JOIN services s ON s.id = a.service_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY a.start_at LIMIT 1000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $q = (string)($_GET['q'] ?? '');
    $now = nowDb();
    $result = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['patient_name']  = decrypt($row['full_name_enc']);
        $row['patient_phone'] = decrypt($row['phone_enc']);
        unset($row['full_name_enc'], $row['phone_enc']);

        if (!textMatches($q, [$row['patient_name'], $row['patient_phone']])) {
            continue;
        }
        foreach (['id', 'user_id', 'doctor_id', 'service_id', 'price', 'duration_min'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['is_past'] = $row['start_at'] < $now;
        $result[] = $row;
    }

    jsonResponse(['appointments' => $result]);
}

$input  = requestData();
$action = inputString($input, 'action');
$id     = inputInt($input, 'id');

$existing = null;
if ($action !== 'create') {
    $stmt = db()->prepare('SELECT * FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch() ?: jsonError('Запись не найдена.', 404);
}

try {
    switch ($action) {
        case 'status':
            $status = inputString($input, 'status');
            if (!in_array($status, APPOINTMENT_STATUSES, true)) {
                jsonError('Неизвестный статус.', 422);
            }
            db()->prepare('UPDATE appointments SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, date('Y-m-d H:i:s'), $id]);
            auditLog($admin['id'], 'admin_appointment_status', 'appointments', $id);
            jsonResponse(['ok' => true]);

        case 'delete':
            db()->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
            auditLog($admin['id'], 'admin_appointment_delete', 'appointments', $id);
            jsonResponse(['ok' => true]);

        case 'create':
        case 'update':
            [$data, $errors] = validateAppointment($input);

            if ($action === 'create') {
                $patient = findUserById(inputInt($input, 'user_id'));
                if (!$patient || $patient['role'] !== 'patient') {
                    $errors['user_id'] = 'Выберите пациента.';
                }
            }
            if ($errors) {
                jsonError('Проверьте правильность заполнения формы.', 422, $errors);
            }

            if ($action === 'create') {
                db()->prepare(
                    'INSERT INTO appointments (user_id, doctor_id, service_id, start_at, end_at, status, comment, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([(int)$patient['id'], $data['doctor_id'], $data['service_id'], $data['start_at'],
                            $data['end_at'], $data['status'], $data['comment'], date('Y-m-d H:i:s')]);
                $id = (int)db()->lastInsertId();
            } else {
                db()->prepare(
                    'UPDATE appointments
                     SET doctor_id = ?, service_id = ?, start_at = ?, end_at = ?, status = ?, comment = ?, updated_at = ?
                     WHERE id = ?'
                )->execute([$data['doctor_id'], $data['service_id'], $data['start_at'], $data['end_at'],
                            $data['status'], $data['comment'], date('Y-m-d H:i:s'), $id]);
            }
            auditLog($admin['id'], "admin_appointment_$action", 'appointments', $id);
            jsonResponse(['id' => $id], $action === 'create' ? 201 : 200);

        default:
            jsonError('Неизвестное действие.', 400);
    }
} catch (PDOException $e) {
    if (isSlotTakenError($e)) {
        jsonError('У врача или у пациента уже есть приём, пересекающийся с этим временем.', 409, ['time' => 'Время занято.']);
    }
    throw $e;
}
