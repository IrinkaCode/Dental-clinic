<?php
/**
 * GET api/services.php — услуги, на которые можно записаться.
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

requireMethod('GET');

jsonResponse(['services' => activeServices()]);
