<?php
/**
 * Дни и время для записи
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');
$user = requireAuth('patient');

$doctorId  = (int)($_GET['doctor_id'] ?? 0);
$serviceId = (int)($_GET['service_id'] ?? 0);
$service   = findActiveService($serviceId);

if (!$service || !doctorProvidesService($doctorId, $serviceId)) {
    jsonError('Врач не оказывает эту услугу.', 404);
}

jsonResponse(['days' => availableDays($doctorId, $service['duration_min'], $user['id'])]);
