<?php
/**
 * Чтение настроек из config/config.php
 */

declare(strict_types=1);

const CONFIG_PATH = __DIR__ . '/../config/config.php';

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        if (!is_file(CONFIG_PATH)) {
            throw new RuntimeException('Не найден config/config.php. Выполните: php install.php');
        }
        $config = require CONFIG_PATH;
    }

    return $config[$key] ?? $default;
}
