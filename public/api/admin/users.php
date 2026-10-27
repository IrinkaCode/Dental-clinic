<?php
/**
 * Пользователи (только администратор).
 *
 * GET  api/admin/users.php?q=&role=&status=   — список с поиском и фильтрами
 * POST api/admin/users.php  {action: 'create' | 'update' | 'delete', ...}
 *
 * Персональные данные зашифрованы, поэтому SQL не может искать по ним.
 * Поиск идёт в PHP по расшифрованным значениям — для клиники с тысячами
 * пациентов это быстро.
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();

if (!isPost()) {
    $q      = (string)($_GET['q'] ?? '');
    $role   = (string)($_GET['role'] ?? '');
    $status = (string)($_GET['status'] ?? '');

    $users = [];
    foreach (db()->query('SELECT * FROM users ORDER BY id')->fetchAll() as $row) {
        $user = publicUser($row);
        if ($role !== '' && $user['role'] !== $role) {
            continue;
        }
        if ($status !== '' && (int)$user['is_active'] !== (int)$status) {
            continue;
        }
        if (!textMatches($q, [$user['full_name'], $user['email'], $user['phone']])) {
            continue;
        }
        $users[] = $user;
    }
    jsonResponse(['users' => $users]);
}

$input  = requestData();
$action = inputString($input, 'action');
$id     = inputInt($input, 'id');

if ($action === 'delete') {
    $target = findUserById($id) ?? jsonError('Пользователь не найден.', 404);

    if ($id === $admin['id']) {
        jsonError('Нельзя удалить собственную учётную запись.', 409);
    }
    if ($target['role'] === 'admin' && otherActiveAdmins($id) === 0) {
        jsonError('Нельзя удалить последнего администратора.', 409);
    }
    // Записи пациента удаляются вместе с ним (ON DELETE CASCADE)
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    auditLog($admin['id'], 'admin_user_delete', 'users', $id);
    jsonResponse(['ok' => true]);
}

if ($action !== 'create' && $action !== 'update') {
    jsonError('Неизвестное действие.', 400);
}

$target = null;
if ($action === 'update') {
    $target = findUserById($id) ?? jsonError('Пользователь не найден.', 404);
}

// ---- Проверка данных ----
[$data, $errors] = validateProfile($input);

$role     = inputString($input, 'role');
$isActive = !inputBool($input, 'is_blocked');
$password = inputRaw($input, 'password');

if (!in_array($role, ['patient', 'admin'], true)) {
    $errors['role'] = 'Выберите роль.';
}
// Телефон обязателен только для пациентов
if ($role === 'admin' && inputString($input, 'phone') === '') {
    unset($errors['phone']);
    $data['phone'] = null;
}
if ($action === 'create' || $password !== '') {
    if ($error = passwordError($password)) {
        $errors['password'] = $error;
    }
}
if (!isset($errors['email'])) {
    $owner = findUserByEmail($data['email']);
    if ($owner && (int)$owner['id'] !== $id) {
        $errors['email'] = 'Этот email уже используется.';
    }
}

// Защита от потери доступа к админке
if ($action === 'update') {
    if ($id === $admin['id'] && ($role !== 'admin' || !$isActive)) {
        jsonError('Нельзя заблокировать себя или снять с себя роль администратора.', 409);
    }
    if ($target['role'] === 'admin' && ($role !== 'admin' || !$isActive) && otherActiveAdmins($id) === 0) {
        jsonError('Должен остаться хотя бы один активный администратор.', 409);
    }
}

if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

// ---- Сохранение ----
if ($action === 'create') {
    $id = createUser($data + ['password' => $password], $role);
    db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([(int)$isActive, $id]);
    auditLog($admin['id'], 'admin_user_create', 'users', $id);
    jsonResponse(['user' => publicUser(findUserById($id))], 201);
}

db()->prepare(
    'UPDATE users
     SET email_hash = ?, email_enc = ?, full_name_enc = ?, phone_enc = ?, birth_date_enc = ?,
         role = ?, is_active = ?, updated_at = ?
     WHERE id = ?'
)->execute([
    emailHash($data['email']),
    encrypt($data['email']),
    encrypt($data['full_name']),
    encrypt($data['phone']),
    encrypt($data['birth_date']),
    $role,
    (int)$isActive,
    date('Y-m-d H:i:s'),
    $id,
]);

if ($password !== '') {
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([hashPassword($password), $id]);
}
if ($isActive) {
    resetFailedLogins($id);   // разблокировка снимает и временный запрет входа
}

auditLog($admin['id'], 'admin_user_update', 'users', $id);
jsonResponse(['user' => publicUser(findUserById($id))]);
