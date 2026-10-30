<?php
/**
 * Подключение к базе данных SQLite через PDO.
 */

declare(strict_types=1);

const DB_PATH = __DIR__ . '/../database/clinic.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!is_file(DB_PATH)) {
        throw new RuntimeException('База данных не найдена. Выполните: php install.php');
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');   // в SQLite внешние ключи по умолчанию выключены
    $pdo->exec('PRAGMA journal_mode = WAL');  // надёжнее при одновременных чтении и записи
    $pdo->exec('PRAGMA busy_timeout = 5000'); // ждать до 5 с, если база занята

    return $pdo;
}

/** Проверяет, что исключение PDO вызвано триггером защиты от двойной записи. */
function isSlotTakenError(PDOException $e): bool
{
    return str_contains($e->getMessage(), 'SLOT_TAKEN');
}
