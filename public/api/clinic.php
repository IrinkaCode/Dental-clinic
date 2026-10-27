<?php
/**
 * GET api/clinic.php — контактная информация клиники (доступна всем).
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');

jsonResponse(['clinic' => clinicInfo()]);
