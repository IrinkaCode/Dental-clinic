<?php
/**
 * Текущий пользователь и CSRF-токен
 * Если пользователь не вошёл, user = null
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');

jsonResponse([
    'user'       => currentUser(),
    'csrf_token' => csrfToken(),
]);
