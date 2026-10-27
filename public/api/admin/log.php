<?php
/**
 * GET api/admin/log.php — последние 300 записей журнала действий.
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

adminStart();

$rows = db()->query(
    'SELECT l.id, l.user_id, l.action, l.table_name, l.record_id, l.created_at,
            u.full_name_enc, u.role
     FROM audit_log l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.id DESC
     LIMIT 300'
)->fetchAll();

foreach ($rows as &$row) {
    $row['user_name'] = $row['full_name_enc'] ? decrypt($row['full_name_enc']) : null;
    unset($row['full_name_enc']);
}

jsonResponse(['log' => $rows]);
