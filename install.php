<?php
/**
 * Первичная установка приложения. Запускать из корня проекта:
 *
 *   php install.php          — создать ключи, базу данных и администратора
 *   php install.php --demo   — то же + демо-пациент с историей и будущими записями
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запускайте из командной строки: php install.php');
}

foreach (['pdo_sqlite', 'sodium'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Не подключено расширение $ext. Включите extension=$ext в php.ini\n");
        exit(1);
    }
}

require __DIR__ . '/src/users.php';   // подключает также config.php, crypto.php и db.php

$configFile = __DIR__ . '/config/config.php';
$schemaFile = __DIR__ . '/database/schema.sql';
$withDemo   = in_array('--demo', $argv, true);

if (is_file(DB_PATH)) {
    fwrite(STDERR, "База данных уже существует: database/clinic.sqlite\n"
                 . "Удалите этот файл, если хотите выполнить установку заново.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 1. Ключи шифрования
// ---------------------------------------------------------------------
if (!is_file($configFile)) {
    if (!is_dir(dirname($configFile))) {
        mkdir(dirname($configFile), 0700, true);
    }
    $content = "<?php\n"
             . "// Ключи шифрования. Создано install.php " . date('Y-m-d H:i') . "\n"
             . "// Не публикуйте этот файл и не теряйте его: без ключей зашифрованные\n"
             . "// данные пользователей восстановить невозможно.\n"
             . "//\n"
             . "// debug: true — подробный текст ошибок в ответах API (только для разработки).\n"
             . "//        На хостинге обязательно false: ошибки пишутся в logs/php-error.log.\n"
             . "// timezone: часовой пояс клиники.\n"
             . 'return ' . var_export(generateCryptoKeys() + ['debug' => false, 'timezone' => 'Europe/Moscow'], true) . ";\n";
    file_put_contents($configFile, $content);
    echo "[OK] Созданы ключи шифрования: config/config.php\n";
} else {
    echo "[OK] Используются существующие ключи: config/config.php\n";
}

date_default_timezone_set((string)config('timezone', 'Europe/Moscow'));

// ---------------------------------------------------------------------
// 2. База данных
// ---------------------------------------------------------------------
try {
    touch(DB_PATH);
    db()->exec(file_get_contents($schemaFile));
    echo "[OK] Создана база данных: database/clinic.sqlite\n";
} catch (Throwable $e) {
    @unlink(DB_PATH);
    fwrite(STDERR, 'Ошибка при создании базы: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Вспомогательные функции
// ---------------------------------------------------------------------
function ask(string $question, string $default = ''): string
{
    echo $question . ($default !== '' ? " [$default]" : '') . ': ';
    $line = fgets(STDIN);
    $line = $line === false ? '' : trim($line);
    return $line === '' ? $default : $line;
}

/** Ближайший рабочий день (пн–пт), сдвинутый на $days дней от сегодня. */
function workday(int $days): string
{
    $date = new DateTime("today $days days");
    while ((int)$date->format('N') > 5) {
        $date->modify($days >= 0 ? '+1 day' : '-1 day');
    }
    return $date->format('Y-m-d');
}

// ---------------------------------------------------------------------
// 3. Учётная запись администратора
// ---------------------------------------------------------------------
echo "\nСоздание учётной записи администратора\n";

do {
    $adminEmail = ask('Email администратора', 'admin@clinic.local');
    $validEmail = filter_var($adminEmail, FILTER_VALIDATE_EMAIL) !== false;
    if (!$validEmail) {
        echo "Некорректный email, попробуйте ещё раз.\n";
    }
} while (!$validEmail);

$adminPassword = ask('Пароль (не менее 8 символов, Enter — сгенерировать)');
$generated = false;
if ($adminPassword === '') {
    $adminPassword = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kx'), '=');
    $generated = true;
}
if (strlen($adminPassword) < 8) {
    fwrite(STDERR, "Пароль короче 8 символов. Установка прервана.\n");
    @unlink(DB_PATH);
    exit(1);
}

createUser([
    'email'     => $adminEmail,
    'password'  => $adminPassword,
    'full_name' => 'Администратор',
], 'admin');
echo "[OK] Администратор создан: " . normalizeEmail($adminEmail) . "\n";
if ($generated) {
    echo "     Сгенерированный пароль: $adminPassword  (запишите его)\n";
}

// ---------------------------------------------------------------------
// 4. Демо-пациент (по флагу --demo)
// ---------------------------------------------------------------------
if ($withDemo) {
    $patientId = createUser([
        'email'      => 'patient@demo.local',
        'password'   => 'Demo12345',
        'full_name'  => 'Иванова Анна Сергеевна',
        'phone'      => '+7 (900) 123-45-67',
        'birth_date' => '1995-04-12',
    ]);

    $duration = db()->query('SELECT id, duration_min FROM services')->fetchAll(PDO::FETCH_KEY_PAIR);

    // [дата, время, врач, услуга, статус, комментарий]
    $visits = [
        [workday(-60), '10:00', 1, 1, 'completed', 'Первичный осмотр'],
        [workday(-45), '11:00', 1, 3, 'completed', 'Кариес 2.6, установлена пломба'],
        [workday(-20), '14:00', 1, 2, 'cancelled', 'Отменено пациентом'],
        [workday(-10), '10:30', 1, 2, 'completed', null],
        [workday(3),   '12:00', 1, 1, 'planned',   null],
        [workday(10),  '15:00', 1, 3, 'planned',   null],
    ];

    $stmt = db()->prepare(
        'INSERT INTO appointments (user_id, doctor_id, service_id, start_at, end_at, status, comment)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($visits as [$date, $time, $doctorId, $serviceId, $status, $comment]) {
        $start = new DateTime("$date $time");
        $end   = (clone $start)->modify('+' . $duration[$serviceId] . ' minutes');
        $stmt->execute([$patientId, $doctorId, $serviceId,
                        $start->format('Y-m-d H:i'), $end->format('Y-m-d H:i'), $status, $comment]);
    }

    echo "[OK] Демо-пациент: patient@demo.local / Demo12345 (" . count($visits) . " записей)\n";
}

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0750, true);
}

echo "\nУстановка завершена. Запустите приложение: start.bat\n";
