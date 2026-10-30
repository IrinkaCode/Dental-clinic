<?php
/**
 * Работа с пользователями: поиск, создание, проверка данных,
 * защита от подбора пароля.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/db.php';

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 72;   // ограничение bcrypt; для единообразия и для Argon2id
const MAX_FAILED_LOGINS   = 5;    // после стольких ошибок подряд вход блокируется
const LOGIN_LOCK_MINUTES  = 15;   // на столько минут

// ---------------------------------------------------------------------
//  Поиск и создание
// ---------------------------------------------------------------------

function findUserByEmail(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email_hash = ?');
    $stmt->execute([emailHash($email)]);
    return $stmt->fetch() ?: null;
}

function findUserById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Создаёт пользователя. Персональные данные шифруются, пароль хешируется.
 */
function createUser(array $data, string $role = 'patient'): int
{
    $stmt = db()->prepare(
        'INSERT INTO users (email_hash, email_enc, password_hash, full_name_enc,
                            phone_enc, birth_date_enc, role, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        emailHash($data['email']),
        encrypt(normalizeEmail($data['email'])),
        hashPassword($data['password']),
        encrypt($data['full_name']),
        encrypt($data['phone'] ?? null),
        encrypt($data['birth_date'] ?? null),
        $role,
        date('Y-m-d H:i:s'),   // время по часовому поясу клиники, а не сервера
    ]);
    return (int)db()->lastInsertId();
}

/** Расшифрованные данные пользователя для отправки в браузер (без хешей). */
function publicUser(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'email'      => decrypt($row['email_enc']),
        'full_name'  => decrypt($row['full_name_enc']),
        'phone'      => decrypt($row['phone_enc']),
        'birth_date' => decrypt($row['birth_date_enc']),
        'role'       => $row['role'],
        'is_active'  => (bool)$row['is_active'],
        'created_at' => $row['created_at'],
    ];
}

// ---------------------------------------------------------------------
//  Защита от подбора пароля
// ---------------------------------------------------------------------

/** Сколько минут осталось до снятия блокировки входа (0 — не заблокирован). */
function loginLockMinutesLeft(array $user): int
{
    if (empty($user['locked_until'])) {
        return 0;
    }
    $left = strtotime($user['locked_until']) - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

function registerFailedLogin(array $user): void
{
    $failed = (int)$user['failed_logins'] + 1;
    $lockedUntil = null;

    if ($failed >= MAX_FAILED_LOGINS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
        $failed = 0;
    }

    db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
        ->execute([$failed, $lockedUntil, $user['id']]);
}

function resetFailedLogins(int $userId): void
{
    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')
        ->execute([$userId]);
}

// ---------------------------------------------------------------------
//  Проверка введённых данных
// ---------------------------------------------------------------------

/**
 * Приводит телефон к виду +7 (900) 123-45-67.
 * Российские номера можно вводить с 8, +7 или без кода. Возвращает null, если номер некорректен.
 */
function normalizePhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    if (strlen($digits) === 11 && $digits[0] === '7') {
        return sprintf('+7 (%s) %s-%s-%s',
            substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
    }
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return '+' . $digits;   // иностранный номер
    }
    return null;
}

/** Возвращает текст ошибки или null, если пароль подходит. */
function passwordError(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Пароль должен быть не короче ' . PASSWORD_MIN_LENGTH . ' символов.';
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return 'Пароль слишком длинный.';
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return 'Пароль должен содержать буквы и цифры.';
    }
    return null;
}

/**
 * Проверяет персональные данные (регистрация и изменение профиля).
 * Возвращает [очищенные данные, ошибки по полям].
 */
function validateProfile(array $input): array
{
    $clean  = [];
    $errors = [];

    $fullName = preg_replace('/\s+/u', ' ', inputString($input, 'full_name'));
    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
        $errors['full_name'] = 'Укажите ФИО (от 2 до 100 символов).';
    } elseif (!preg_match("/^[\\p{L}][\\p{L}\\s.'’-]*$/u", $fullName)) {
        $errors['full_name'] = 'ФИО может содержать только буквы, пробелы, дефис и точку.';
    }
    $clean['full_name'] = $fullName;

    $email = normalizeEmail(inputString($input, 'email'));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Укажите корректный email.';
    }
    $clean['email'] = $email;

    $phone = normalizePhone(inputString($input, 'phone'));
    if ($phone === null) {
        $errors['phone'] = 'Укажите телефон, например +7 (900) 123-45-67.';
    }
    $clean['phone'] = $phone;

    $birth = inputString($input, 'birth_date');
    $clean['birth_date'] = null;
    if ($birth !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $birth);
        $valid = $date && $date->format('Y-m-d') === $birth;
        if (!$valid || $date > new DateTime('today') || $date < new DateTime('-120 years')) {
            $errors['birth_date'] = 'Укажите корректную дату рождения.';
        } else {
            $clean['birth_date'] = $birth;
        }
    }

    return [$clean, $errors];
}

/** Безопасно достаёт строковое значение из входных данных. */
function inputString(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}
