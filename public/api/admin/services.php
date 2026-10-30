<?php
/**
 * Услуги (только администратор)
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();

if (!isPost()) {
    $services = db()->query(
        'SELECT s.id, s.name, s.description, s.price, s.duration_min, s.is_active,
                (SELECT COUNT(*) FROM doctor_services ds WHERE ds.service_id = s.id) AS doctors_count
         FROM services s ORDER BY s.name'
    )->fetchAll();

    foreach ($services as &$service) {
        foreach (['id', 'price', 'duration_min', 'doctors_count'] as $key) {
            $service[$key] = (int)$service[$key];
        }
        $service['is_active'] = (bool)$service['is_active'];
    }
    jsonResponse(['services' => $services]);
}

$input  = requestData();
$action = inputString($input, 'action');
$id     = inputInt($input, 'id');

if ($action === 'update' || $action === 'delete') {
    $stmt = db()->prepare('SELECT id FROM services WHERE id = ?');
    $stmt->execute([$id]);
    $stmt->fetch() ?: jsonError('Услуга не найдена.', 404);
}

if ($action === 'delete') {
    $stmt = db()->prepare('SELECT COUNT(*) FROM appointments WHERE service_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('На эту услугу есть записи, удалить её нельзя. Снимите отметку «Доступна для записи».', 409);
    }
    db()->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    auditLog($admin['id'], 'admin_service_delete', 'services', $id);
    jsonResponse(['ok' => true]);
}

if ($action !== 'create' && $action !== 'update') {
    jsonError('Неизвестное действие.', 400);
}

$name        = preg_replace('/\s+/u', ' ', inputString($input, 'name'));
$description = mb_substr(inputString($input, 'description'), 0, 500);
$price       = inputInt($input, 'price');
$duration    = inputInt($input, 'duration_min');
$isActive    = inputBool($input, 'is_active');

$errors = [];
if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
    $errors['name'] = 'Укажите название (от 3 до 100 символов).';
}
if ($price < 0 || $price > 1000000) {
    $errors['price'] = 'Укажите цену от 0 до 1 000 000 ₽.';
}
if ($duration < 15 || $duration > 480 || $duration % 15 !== 0) {
    $errors['duration_min'] = 'Длительность — от 15 минут до 8 часов, кратно 15 минутам.';
}
if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

try {
    if ($action === 'create') {
        db()->prepare('INSERT INTO services (name, description, price, duration_min, is_active) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $description ?: null, $price, $duration, (int)$isActive]);
        $id = (int)db()->lastInsertId();
    } else {
        db()->prepare('UPDATE services SET name = ?, description = ?, price = ?, duration_min = ?, is_active = ? WHERE id = ?')
            ->execute([$name, $description ?: null, $price, $duration, (int)$isActive, $id]);
    }
} catch (PDOException $e) {
    if (isUniqueViolation($e)) {
        jsonError('Проверьте правильность заполнения формы.', 422, ['name' => 'Услуга с таким названием уже есть.']);
    }
    throw $e;
}

auditLog($admin['id'], "admin_service_$action", 'services', $id);
jsonResponse(['id' => $id], $action === 'create' ? 201 : 200);
