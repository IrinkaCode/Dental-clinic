<?php
/**
 * GET api/admin/stats.php — показатели для главной страницы панели.
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

adminStart();

function countBy(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$now      = nowDb();
$today    = date('Y-m-d');
$weekEnd  = date('Y-m-d', strtotime('+7 days'));

jsonResponse([
    'patients' => countBy("SELECT COUNT(*) FROM users WHERE role = 'patient'"),
    'today'    => countBy("SELECT COUNT(*) FROM appointments WHERE status <> 'cancelled' AND start_at BETWEEN ? AND ?",
                          ["$today 00:00", "$today 23:59"]),
    'week'     => countBy("SELECT COUNT(*) FROM appointments WHERE status = 'planned' AND start_at BETWEEN ? AND ?",
                          [$now, "$weekEnd 23:59"]),
    'overdue'  => countBy("SELECT COUNT(*) FROM appointments WHERE status = 'planned' AND start_at < ?", [$now]),
]);
