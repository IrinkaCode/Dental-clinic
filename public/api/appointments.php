<?php
/**
 * Записи текущего пациента
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');
$user = requireAuth('patient');

jsonResponse(appointmentsForUser($user['id']) + ['cancel_min_hours' => CANCEL_MIN_HOURS]);
