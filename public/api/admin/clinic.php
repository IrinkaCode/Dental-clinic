<?php
/**
 * Контакты клиники (только администратор). Чтение — общий api/clinic.php.
 *
 * POST api/admin/clinic.php  {name, address, phone, email, working_hours}
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

$admin = adminStart();
if (!isPost()) {
    jsonResponse(['clinic' => clinicInfo()]);
}

$input  = requestData();
$fields = [
    'name'          => 'Название',
    'address'       => 'Адрес',
    'phone'         => 'Телефон',
    'email'         => 'Email',
    'working_hours' => 'Часы работы',
];

$values = [];
$errors = [];
foreach ($fields as $key => $label) {
    $value = inputString($input, $key);
    if ($value === '' || mb_strlen($value) > 200) {
        $errors[$key] = "Заполните поле «{$label}» (до 200 символов).";
    }
    $values[$key] = $value;
}
if (!isset($errors['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Укажите корректный email.';
}
if ($errors) {
    jsonError('Проверьте правильность заполнения формы.', 422, $errors);
}

$stmt = db()->prepare('INSERT OR REPLACE INTO clinic_info (key, value) VALUES (?, ?)');
foreach ($values as $key => $value) {
    $stmt->execute([$key, $value]);
}

auditLog($admin['id'], 'admin_clinic_update', 'clinic_info', null);
jsonResponse(['clinic' => clinicInfo()]);
