<?php
/**
 * POST api/logout.php — выход.
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('POST');
verifyCsrf();

if (isset($_SESSION['user_id'])) {
    auditLog((int)$_SESSION['user_id'], 'logout', 'users', (int)$_SESSION['user_id']);
}
logoutSession();

jsonResponse(['ok' => true]);
