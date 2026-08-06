<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

session_start();
schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => t('api.upload.no_file', 'Keine Datei empfangen.')]);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => t('api.upload.failed', 'Upload fehlgeschlagen (Code {code}).', ['code' => (int) $file['error']])]);
    exit;
}

$images = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
$videos = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
];
$allowed = $images + $videos;

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => t('api.upload.type_not_allowed', 'Nur Bilder (JPG, PNG, GIF, WebP) oder Videos (MP4, WebM) erlaubt.')]);
    exit;
}

$isVideo = isset($videos[$mime]);
$maxBytes = $isVideo ? 100 * 1024 * 1024 : 25 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => t('api.upload.too_large', 'Datei zu gross (max. {max}). Sehr grosse Videos brauchen evtl. hoehere upload_max_filesize/post_max_size in der php.ini.', ['max' => $isVideo ? '100 MB' : '25 MB'])]);
    exit;
}

$config = schauboard_config();
$uploadsDir = $config['uploads_dir'] ?? (dirname(__DIR__) . '/uploads');
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => t('api.upload.dir_not_writable', 'Upload-Ordner nicht beschreibbar.')]);
    exit;
}

$name = 'm_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $uploadsDir . '/' . $name)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => t('api.upload.save_failed', 'Datei konnte nicht gespeichert werden.')]);
    exit;
}

// Web-Pfad relativ zur Installation (uploads liegt im Web-Root).
$scriptDir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/upload.php')));
$rootBase = ($scriptDir === '' || $scriptDir === '.') ? '/' : rtrim($scriptDir, '/') . '/';

echo json_encode(['ok' => true, 'url' => $rootBase . 'uploads/' . $name, 'name' => $name]);
