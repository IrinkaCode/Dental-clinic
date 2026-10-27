<?php
/**
 * Сброс пароля и снятие блокировки учётной записи. Запускать из корня проекта:
 *
 *   php reset-password.php                       — показать администраторов
 *   php reset-password.php admin@clinic.local    — задать новый пароль
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запускайте из командной строки.');
}

require __DIR__ . '/src/users.php';

$email = $argv[1] ?? '';

if ($email === '') {
    echo "Администраторы в базе:\n";
    foreach (db()->query("SELECT * FROM users WHERE role = 'admin'") as $row) {
        $state = $row['is_active'] ? 'активен' : 'ЗАБЛОКИРОВАН';
        if (loginLockMinutesLeft($row)) {
            $state .= ', вход временно заблокирован после неудачных попыток';
        }
        echo '  ' . decrypt($row['email_enc']) . "  ($state)\n";
    }
    echo "\nЧтобы сменить пароль: php reset-password.php email\n";
    exit;
}

$user = findUserByEmail($email);
if ($user === null) {
    fwrite(STDERR, "Пользователь с email $email не найден. Запустите без параметров, чтобы увидеть список.\n");
    exit(1);
}

echo 'Новый пароль (не менее ' . PASSWORD_MIN_LENGTH . ' символов, буквы и цифры): ';
$password = trim((string)fgets(STDIN));

if ($error = passwordError($password)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

db()->prepare(
    'UPDATE users
     SET password_hash = ?, failed_logins = 0, locked_until = NULL, is_active = 1,
         updated_at = datetime(\'now\', \'localtime\')
     WHERE id = ?'
)->execute([hashPassword($password), $user['id']]);

echo "Готово. Пароль изменён, блокировка снята. Войдите как " . normalizeEmail($email) . "\n";
