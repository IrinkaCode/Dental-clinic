<?php
/**
 * Врачи (только администратор).
 *
 * GET  api/admin/doctors.php   — все врачи (с id оказываемых услуг) и список всех услуг
 * POST api/admin/doctors.php   {action: 'create' | 'update' | 'delete', ...}
 *      поля: full_name, specialty, description, is_active, service_ids: [1, 2, ...]
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();

if (!isPost()) {
    $doctors = db()->query('SELECT id, full_name, specialty, description, is_active FROM doctors ORDER BY full_name')->fetchAll();
    $links   = db()->query('SELECT doctor_id, service_id FROM doctor_services')->fetchAll();

    foreach ($doctors as &$doctor) {
        $doctor['id']          = (int)$doctor['id'];
        $doctor['is_active']   = (bool)$doctor['is_active'];
        $doctor['service_ids'] = array_values(array_map(
            static fn($link) => (int)$link['service_id'],
            array_filter($links, static fn($link) => (int)$link['doctor_id'] === $doctor['id'])
        ));
    }
    unset($doctor);

    $services = db()->query('SELECT id, name, is_active FROM services ORDER BY name')->fetchAll();
    foreach ($services as &$service) {
        $service['id']        = (int)$service['id'];
        $service['is_active'] = (bool)$service['is_active'];
    }

    jsonResponse(['doctors' => $doctors, 'services' => $services]);
}

$input  = requestData();
$action = inputString($input, 'action');
$id     = inputInt($input, 'id');

if ($action === 'update' || $action === 'delete') {
    $stmt = db()->prepare('SELECT id FROM doctors WHERE id = ?');
    $stmt->execute([$id]);
    $stmt->fetch() ?: jsonError('Врач не найден.', 404);
}

if ($action === 'delete') {
    $stmt = db()->prepare('SELECT COUNT(*) FROM appointments WHERE doctor_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('У врача есть записи пациентов, удалить его нельзя. Снимите отметку «Принимает пациентов» — '
            . 'врач исчезнет из записи, а история посещений сохранится.', 409);
    }
    db()->prepare('DELETE FROM doctors WHERE id = ?')->execute([$id]);
    auditLog($admin['id'], 'admin_doctor_delete', 'doctors', $id);
    jsonResponse(['ok' => true]);
}

if ($action !== 'create' && $action !== 'update') {
    jsonError('Неизвестное действие.', 400);
}

$fullName    = preg_replace('/\s+/u', ' ', inputString($input, 'full_name'));
$specialty   = inputString($input, 'specialty');
$description = mb_substr(inputString($input, 'description'), 0, 1000);
$isActive    = inputBool($input, 'is_active');
$serviceIds  = array_values(array_unique(array_map('intval', (array)($input['service_ids'] ?? []))));

$errors = [];
if (mb_strlen($fullName) < 5 || mb_strlen($fullName) > 100) {
    $errors['full_name'] = 'Укажите ФИО врача (от 5 до 100 символов).';
}
if (mb_strlen($specialty) < 3 || mb_strlen($specialty) > 100) {
    $errors['specialty'] = 'Укажите специальность.';
}
$existingServices = db()->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);
if (array_diff($serviceIds, array_map('intval', $existingServices))) {
    $errors['service_ids'] = 'Выбрана несуществующая услуга.';
}
if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

// Врач и его услуги сохраняются вместе: либо всё, либо ничего
db()->beginTransaction();

if ($action === 'create') {
    db()->prepare('INSERT INTO doctors (full_name, specialty, description, is_active) VALUES (?, ?, ?, ?)')
        ->execute([$fullName, $specialty, $description ?: null, (int)$isActive]);
    $id = (int)db()->lastInsertId();
} else {
    db()->prepare('UPDATE doctors SET full_name = ?, specialty = ?, description = ?, is_active = ? WHERE id = ?')
        ->execute([$fullName, $specialty, $description ?: null, (int)$isActive, $id]);
    db()->prepare('DELETE FROM doctor_services WHERE doctor_id = ?')->execute([$id]);
}

$link = db()->prepare('INSERT INTO doctor_services (doctor_id, service_id) VALUES (?, ?)');
foreach ($serviceIds as $serviceId) {
    $link->execute([$id, $serviceId]);
}

db()->commit();

auditLog($admin['id'], "admin_doctor_$action", 'doctors', $id);
jsonResponse(['id' => $id], $action === 'create' ? 201 : 200);
