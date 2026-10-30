<?php
/**
 * Смена пароля
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();
$user = requireAuth();

$input   = requestData();
$current = inputRaw($input, 'current_password');
$new     = inputRaw($input, 'new_password');
$row     = findUserById($user['id']);
$errors  = [];

if (!verifyPassword($current, $row['password_hash'])) {
    $errors['current_password'] = 'Неверный текущий пароль.';
}
if ($error = passwordError($new)) {
    $errors['new_password'] = $error;
} elseif ($current === $new) {
    $errors['new_password'] = 'Новый пароль совпадает с текущим.';
}
if (!hash_equals($new, inputRaw($input, 'new_password_confirm'))) {
    $errors['new_password_confirm'] = 'Пароли не совпадают.';
}
if ($errors) {
    jsonError('Пароль не изменён.', 422, $errors);
}

db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
    ->execute([hashPassword($new), date('Y-m-d H:i:s'), $user['id']]);

session_regenerate_id(true);
auditLog($user['id'], 'password_change', 'users', $user['id']);

jsonResponse(['ok' => true]);
