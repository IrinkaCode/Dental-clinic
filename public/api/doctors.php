<?php
/**
 * GET api/doctors.php?service_id=1 — врачи, оказывающие услугу (для записи).
 * GET api/doctors.php             — все работающие врачи с их услугами (для главной страницы).
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');

if (isset($_GET['service_id'])) {
    $serviceId = (int)$_GET['service_id'];
    if (!findActiveService($serviceId)) {
        jsonError('Услуга не найдена.', 404);
    }
    jsonResponse(['doctors' => doctorsForService($serviceId)]);
}

jsonResponse(['doctors' => activeDoctors()]);
