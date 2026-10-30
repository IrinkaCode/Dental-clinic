<?php
/**
 * Фото врача (только администратор)
 */

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/admin.php';

const DOCTOR_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const DOCTOR_PHOTO_DIR       = __DIR__ . '/../../img/doctors/';   // public/img/doctors/
const DOCTOR_PHOTO_URL_BASE  = 'img/doctors/';

$admin = adminStart();
if (!isPost()) {
    jsonError('Метод не поддерживается.', 405);
}

$doctorId = (int)($_POST['doctor_id'] ?? 0);
$stmt = db()->prepare('SELECT photo FROM doctors WHERE id = ?');
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch();
if (!$doctor) {
    jsonError('Врач не найден.', 404);
}

/** Удаляет файл фото с диска */
function deleteDoctorPhotoFile(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with($relativePath, DOCTOR_PHOTO_URL_BASE)) {
        return;
    }
    $name = basename($relativePath);
    $path = DOCTOR_PHOTO_DIR . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

// ---- Удаление фото ----
if (($_POST['remove'] ?? '') === '1') {
    deleteDoctorPhotoFile($doctor['photo']);
    db()->prepare('UPDATE doctors SET photo = NULL WHERE id = ?')->execute([$doctorId]);
    auditLog($admin['id'], 'admin_doctor_photo_remove', 'doctors', $doctorId);
    jsonResponse(['photo' => null]);
}

// ---- Загрузка нового фото ----
$file = $_FILES['photo'] ?? null;
if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    jsonError('Выберите файл с фотографией.', 422);
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('Не удалось загрузить файл. Попробуйте ещё раз.', 422);
}
if ($file['size'] > DOCTOR_PHOTO_MAX_BYTES) {
    jsonError('Файл слишком большой. Максимум 5 МБ.', 422);
}

// Тип определяется по содержимому файла, а не по расширению или имени, которые можно подделать
$info = @getimagesize($file['tmp_name']);
$extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
if ($info === false || !isset($extensions[$info[2]])) {
    jsonError('Файл не похож на изображение. Выберите JPEG, PNG или WEBP.', 422);
}
if ($info[0] < 100 || $info[1] < 100) {
    jsonError('Изображение слишком маленькое. Минимум 100×100 пикселей.', 422);
}

if (!is_dir(DOCTOR_PHOTO_DIR) && !mkdir(DOCTOR_PHOTO_DIR, 0755, true) && !is_dir(DOCTOR_PHOTO_DIR)) {
    jsonError('Не удалось сохранить файл: нет доступа к папке на сервере.', 500);
}

$filename = sprintf('d%d-%s.%s', $doctorId, bin2hex(random_bytes(6)), $extensions[$info[2]]);
if (!move_uploaded_file($file['tmp_name'], DOCTOR_PHOTO_DIR . $filename)) {
    jsonError('Не удалось сохранить файл на сервере.', 500);
}

deleteDoctorPhotoFile($doctor['photo']);

$relativePath = DOCTOR_PHOTO_URL_BASE . $filename;
db()->prepare('UPDATE doctors SET photo = ? WHERE id = ?')->execute([$relativePath, $doctorId]);
auditLog($admin['id'], 'admin_doctor_photo_upload', 'doctors', $doctorId);

jsonResponse(['photo' => $relativePath]);
