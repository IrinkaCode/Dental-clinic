<?php
/**
 * GET api/appointments.php — записи текущего пациента:
 * { upcoming: [...], history: [...], cancel_min_hours: 2 }
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');
$user = requireAuth('patient');

jsonResponse(appointmentsForUser($user['id']) + ['cancel_min_hours' => CANCEL_MIN_HOURS]);
