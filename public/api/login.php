<?php
/**
 * Вход
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();

$input    = requestData();
$email    = inputString($input, 'email');
$password = inputRaw($input, 'password');

if ($email === '' || $password === '') {
    jsonError('Введите email и пароль.', 422);
}

// Одинаковое сообщение для неизвестного email и неверного пароля:
// так нельзя выяснить, зарегистрирован ли email в клинике.
$wrongCredentials = 'Неверный email или пароль.';

$user = findUserByEmail($email);

if ($user === null) {
    hashPassword($password);   // тратим то же время, что и на проверку: ответ не выдаёт отсутствие email
    jsonError($wrongCredentials, 401);
}

if ($minutes = loginLockMinutesLeft($user)) {
    jsonError("Слишком много неудачных попыток. Попробуйте снова через $minutes мин.", 429);
}

if (!verifyPassword($password, $user['password_hash'])) {
    registerFailedLogin($user);
    auditLog((int)$user['id'], 'login_failed', 'users', (int)$user['id']);
    jsonError($wrongCredentials, 401);
}

if (!$user['is_active']) {
    jsonError('Учётная запись заблокирована. Обратитесь в клинику.', 403);
}

resetFailedLogins((int)$user['id']);

// Если сменился алгоритм хеширования — обновляем хеш, пока пароль известен
if (passwordNeedsRehash($user['password_hash'])) {
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([hashPassword($password), $user['id']]);
}

loginSession($user);
auditLog((int)$user['id'], 'login', 'users', (int)$user['id']);

jsonResponse([
    'user'       => publicUser($user),
    'redirect'   => homePage($user['role']),
    'csrf_token' => csrfToken(),
]);
