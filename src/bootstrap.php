<?php
/**
 * Общая инициализация для всех обработчиков
 *
 * Здесь настраиваются:
 *  - обработка ошибок (пользователь видит общий текст, подробности пишутся в лог);
 *  - защищённая сессия (флаги cookie включаются автоматически при HTTPS);
 *  - ответы в формате JSON;
 *  - CSRF-защита и проверка прав доступа.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/appointments.php';
require_once __DIR__ . '/booking.php';

const SESSION_IDLE_TIMEOUT = 30 * 60;   // автоматический выход после 30 минут бездействия

// ---------------------------------------------------------------------
//  Ошибки
// ---------------------------------------------------------------------
const LOG_DIR = __DIR__ . '/../logs';

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}
error_reporting(E_ALL);
ini_set('display_errors', '0');          // никогда не выводить ошибки в ответ
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php-error.log');

// Предупреждения PHP превращаем в исключения, чтобы не пропустить ошибку
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $debug = false;
    try {
        $debug = (bool)config('debug', false);
    } catch (Throwable) {
        // конфигурации нет — остаёмся в безопасном режиме
    }
    jsonError($debug ? $e->getMessage() : 'Внутренняя ошибка сервера. Попробуйте позже.', 500);
});

// Часовой пояс клиники: от него зависят «будущие» и «прошедшие» визиты.
// На хостинге сервер может работать по UTC, поэтому задаём явно.
date_default_timezone_set((string)config('timezone', 'Europe/Kaliningrad'));

// ---------------------------------------------------------------------
//  Заголовки ответа
// ---------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');       // ответы с личными данными не кешируются

// ---------------------------------------------------------------------
//  Сессия
// ---------------------------------------------------------------------

/** Открыт ли сайт по HTTPS (учитывает прокси хостинга). */
function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

ini_set('session.use_strict_mode', '1');  // не принимать придуманные злоумышленником id сессии
ini_set('session.use_only_cookies', '1');
session_name('clinic_session');
session_set_cookie_params([
    'lifetime' => 0,            // до закрытия браузера
    'path'     => '/',
    'secure'   => isHttps(),    // на хостинге с HTTPS cookie не уйдёт по незащищённому каналу
    'httponly' => true,         // недоступна из JavaScript
    'samesite' => 'Lax',        // не отправляется с чужих сайтов при POST-запросах
]);
session_start();

// Выход по таймауту бездействия
if (isset($_SESSION['user_id'])) {
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
        unset($_SESSION['user_id'], $_SESSION['role']);
        session_regenerate_id(true);
    } else {
        $_SESSION['last_activity'] = time();
    }
}

function loginSession(array $user): void
{
    session_regenerate_id(true);                       // защита от фиксации сессии
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
}

function logoutSession(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

// ---------------------------------------------------------------------
//  Ответы и входные данные
// ---------------------------------------------------------------------

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ответ с ошибкой
 */
function jsonError(string $message, int $status = 400, array $fields = [], ?string $code = null): never
{
    $body = ['error' => $message];
    if ($fields) {
        $body['fields'] = $fields;
    }
    if ($code !== null) {
        $body['code'] = $code;
    }
    jsonResponse($body, $status);
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        jsonError('Метод не поддерживается.', 405);
    }
}

/** Данные запроса: JSON из тела или обычная форма. */
function requestData(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($type, 'application/json')) {
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($data)) {
            jsonError('Некорректный формат запроса.', 400);
        }
        return $data;
    }
    return $_POST;
}

/** Значение без обрезки пробелов — для паролей. */
function inputRaw(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_string($value) ? $value : '';
}

// ---------------------------------------------------------------------
//  CSRF-защита
//  Токен хранится в сессии и передаётся JavaScript-ом в заголовке
//  X-CSRF-Token. Чужой сайт не может узнать токен, поэтому не сможет
//  выполнить действие от имени пользователя.
// ---------------------------------------------------------------------

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        jsonError('Сессия устарела. Обновите страницу и повторите действие.', 403, [], 'csrf');
    }
}

// ---------------------------------------------------------------------
//  Текущий пользователь и права доступа
// ---------------------------------------------------------------------

/**
 * Пользователь текущей сессии или null.
 * Данные каждый раз берутся из БД: если администратор заблокировал
 * учётную запись или сменил роль, это сработает сразу.
 */
function currentUser(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }

    $user = null;
    if (isset($_SESSION['user_id'])) {
        $row = findUserById((int)$_SESSION['user_id']);
        if ($row && $row['is_active']) {
            $user = publicUser($row);
            $_SESSION['role'] = $row['role'];
        } else {
            unset($_SESSION['user_id'], $_SESSION['role']);
        }
    }
    return $user;
}

/** Требует входа (и при необходимости роли). Возвращает пользователя. */
function requireAuth(?string $role = null): array
{
    $user = currentUser();
    if ($user === null) {
        jsonError('Войдите в систему.', 401, [], 'unauthorized');
    }
    if ($role !== null && $user['role'] !== $role) {
        jsonError('Недостаточно прав для этого действия.', 403, [], 'forbidden');
    }
    return $user;
}

/** Куда направить пользователя после входа. */
function homePage(string $role): string
{
    return $role === 'admin' ? 'admin.html' : 'cabinet.html';
}

function auditLog(?int $userId, string $action, ?string $table = null, ?int $recordId = null): void
{
    db()->prepare('INSERT INTO audit_log (user_id, action, table_name, record_id, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $action, $table, $recordId, date('Y-m-d H:i:s')]);
}
