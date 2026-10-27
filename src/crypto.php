<?php
/**
 * Криптографические функции приложения.
 *
 *  encrypt() / decrypt()  — шифрование персональных данных алгоритмом
 *                           XSalsa20-Poly1305 (libsodium, secretbox). Без ключа
 *                           данные нельзя ни прочитать, ни незаметно изменить.
 *  emailHash()            — HMAC-SHA256 от email. Позволяет найти пользователя
 *                           по email при входе, не храня email в открытом виде.
 *  hashPassword() / verifyPassword() — односторонний хеш пароля (Argon2id или bcrypt).
 *
 * Ключи хранятся в config/config.php — вне папки public и вне базы данных.
 * Файл создаётся скриптом install.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Префикс версии формата: позволит в будущем сменить алгоритм. */
const CRYPTO_PREFIX = 'v1:';

/**
 * Загружает ключи из config/config.php (один раз за запрос).
 *
 * @return array{enc: string, hmac: string}
 */
function cryptoKeys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $enc  = base64_decode((string)config('enc_key', ''), true);
    $hmac = base64_decode((string)config('hmac_key', ''), true);

    if ($enc === false || strlen($enc) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Некорректный ключ шифрования enc_key в config/config.php');
    }
    if ($hmac === false || strlen($hmac) < 32) {
        throw new RuntimeException('Некорректный ключ hmac_key в config/config.php');
    }

    return $keys = ['enc' => $enc, 'hmac' => $hmac];
}

/**
 * Шифрует строку. Для каждого значения генерируется случайный nonce,
 * поэтому одинаковые данные дают разный шифротекст.
 * Результат: "v1:" + base64(nonce + шифротекст).
 */
function encrypt(?string $plain): ?string
{
    if ($plain === null) {
        return null;
    }

    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, cryptoKeys()['enc']);

    return CRYPTO_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * Расшифровывает строку, полученную из encrypt().
 * Бросает исключение, если данные повреждены или ключ неверный.
 */
function decrypt(?string $stored): ?string
{
    if ($stored === null) {
        return null;
    }
    if (!str_starts_with($stored, CRYPTO_PREFIX)) {
        throw new RuntimeException('Неизвестный формат зашифрованных данных');
    }

    $raw = base64_decode(substr($stored, strlen(CRYPTO_PREFIX)), true);
    $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    if ($raw === false || strlen($raw) < $minLength) {
        throw new RuntimeException('Зашифрованные данные повреждены');
    }

    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, cryptoKeys()['enc']);

    if ($plain === false) {
        throw new RuntimeException('Не удалось расшифровать данные: неверный ключ или данные изменены');
    }
    return $plain;
}

/** Приводит email к единому виду: без пробелов по краям, в нижнем регистре. */
function normalizeEmail(string $email): string
{
    $email = trim($email);
    return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
}

/**
 * «Слепой индекс» email: HMAC-SHA256 с секретным ключом.
 * Одинаковый email всегда даёт одинаковый хеш, поэтому по нему можно искать:
 *   SELECT * FROM users WHERE email_hash = :hash
 * Без ключа восстановить email по хешу нельзя (в отличие от обычного SHA-256).
 */
function emailHash(string $email): string
{
    return hash_hmac('sha256', normalizeEmail($email), cryptoKeys()['hmac']);
}

/** Алгоритм хеширования паролей: Argon2id, если доступен, иначе bcrypt. */
function passwordAlgorithm(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

/** Хеширует пароль. Соль генерируется автоматически и хранится внутри хеша. */
function hashPassword(string $password): string
{
    return password_hash($password, passwordAlgorithm());
}

/** Проверяет пароль по хешу (сравнение защищено от атак по времени). */
function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Нужно ли пересчитать хеш (например, после смены алгоритма).
 * Вызывайте после успешного входа и при true сохраняйте новый hashPassword().
 */
function passwordNeedsRehash(string $hash): bool
{
    return password_needs_rehash($hash, passwordAlgorithm());
}

/** Генерирует новые ключи шифрования (используется в install.php). */
function generateCryptoKeys(): array
{
    return [
        'enc_key'  => base64_encode(sodium_crypto_secretbox_keygen()),
        'hmac_key' => base64_encode(random_bytes(32)),
    ];
}
