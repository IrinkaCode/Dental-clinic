<?php
/**
 * POST api/register.php — регистрация пациента.
 * Тело (JSON): full_name, email, phone, birth_date, password, password_confirm, consent
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();

$input = requestData();
[$data, $errors] = validateProfile($input);

$password = inputRaw($input, 'password');
if ($passwordError = passwordError($password)) {
    $errors['password'] = $passwordError;
}
if (!hash_equals($password, inputRaw($input, 'password_confirm'))) {
    $errors['password_confirm'] = 'Пароли не совпадают.';
}
if (empty($input['consent'])) {
    $errors['consent'] = 'Необходимо согласие на обработку персональных данных.';
}
if (!isset($errors['email']) && findUserByEmail($data['email'])) {
    $errors['email'] = 'Этот email уже зарегистрирован. Войдите или укажите другой.';
}

if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

$data['password'] = $password;
$userId = createUser($data, 'patient');
$user   = findUserById($userId);

loginSession($user);
auditLog($userId, 'register', 'users', $userId);

jsonResponse([
    'user'       => publicUser($user),
    'redirect'   => homePage($user['role']),
    'csrf_token' => csrfToken(),
], 201);
