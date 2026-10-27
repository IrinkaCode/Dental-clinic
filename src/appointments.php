<?php
/**
 * Записи на приём: выборка для кабинета, отмена.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** За сколько часов до приёма пациент ещё может отменить запись сам. */
const CANCEL_MIN_HOURS = 2;

/** Текущее время в формате, в котором даты хранятся в БД. */
function nowDb(): string
{
    return date('Y-m-d H:i');
}

/**
 * Все записи пациента, разделённые на будущие и историю.
 * Будущие — запланированные и ещё не наступившие (по возрастанию даты).
 * История — всё остальное (по убыванию даты).
 */
function appointmentsForUser(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT a.id, a.start_at, a.end_at, a.status, a.comment,
                d.full_name AS doctor_name, d.specialty AS doctor_specialty,
                s.name AS service_name, s.price, s.duration_min
         FROM appointments a
         JOIN doctors  d ON d.id = a.doctor_id
         JOIN services s ON s.id = a.service_id
         WHERE a.user_id = ?
         ORDER BY a.start_at'
    );
    $stmt->execute([$userId]);

    $now = nowDb();
    $upcoming = [];
    $history  = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']           = (int)$row['id'];
        $row['price']        = (int)$row['price'];
        $row['duration_min'] = (int)$row['duration_min'];
        $row['can_cancel']   = $row['status'] === 'planned' && canCancelAt($row['start_at']);

        if ($row['status'] === 'planned' && $row['start_at'] >= $now) {
            $upcoming[] = $row;
        } else {
            $history[] = $row;
        }
    }

    return ['upcoming' => $upcoming, 'history' => array_reverse($history)];
}

/** Можно ли ещё отменить приём, начинающийся в $startAt. */
function canCancelAt(string $startAt): bool
{
    return strtotime($startAt) - time() >= CANCEL_MIN_HOURS * 3600;
}

/** Контактная информация клиники в виде массива ключ => значение. */
function clinicInfo(): array
{
    return db()->query('SELECT key, value FROM clinic_info')->fetchAll(PDO::FETCH_KEY_PAIR);
}
