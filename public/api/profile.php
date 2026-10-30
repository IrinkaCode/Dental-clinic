<?php
/**
 * Изменение личных данных
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();
$user = requireAuth();

[$data, $errors] = validateProfile(requestData());

if (!isset($errors['email'])) {
    $owner = findUserByEmail($data['email']);
    if ($owner && (int)$owner['id'] !== $user['id']) {
        $errors['email'] = 'Этот email уже используется другим пользователем.';
    }
}
if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

db()->prepare(
    'UPDATE users
     SET email_hash = ?, email_enc = ?, full_name_enc = ?, phone_enc = ?, birth_date_enc = ?, updated_at = ?
     WHERE id = ?'
)->execute([
    emailHash($data['email']),
    encrypt($data['email']),
    encrypt($data['full_name']),
    encrypt($data['phone']),
    encrypt($data['birth_date']),
    date('Y-m-d H:i:s'),
    $user['id'],
]);

auditLog($user['id'], 'profile_update', 'users', $user['id']);

jsonResponse(['user' => publicUser(findUserById($user['id']))]);
