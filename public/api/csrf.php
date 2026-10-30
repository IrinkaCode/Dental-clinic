<?php
/**
 * Выдаёт CSRF-токен для форм входа и регистрации.
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');

jsonResponse(['csrf_token' => csrfToken()]);
