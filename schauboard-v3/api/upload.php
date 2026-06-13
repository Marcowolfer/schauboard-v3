<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

session_start();
schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Keine Datei empfangen.']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload fehlgeschlagen (Code ' . (int) $file['error'] . ').']);
    exit;
}

$maxBytes = 25 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Datei zu gross (max. 25 MB).']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Nur Bilder (JPG, PNG, GIF, WebP) erlaubt.']);
    exit;
}

$config = schauboard_config();
$uploadsDir = $config['uploads_dir'] ?? (dirname(__DIR__) . '/uploads');
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Upload-Ordner nicht beschreibbar.']);
    exit;
}

$name = 'm_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $uploadsDir . '/' . $name)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.']);
    exit;
}

// Web-Pfad relativ zur Installation (uploads liegt im Web-Root).
$scriptDir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/upload.php')));
$rootBase = ($scriptDir === '' || $scriptDir === '.') ? '/' : rtrim($scriptDir, '/') . '/';

echo json_encode(['ok' => true, 'url' => $rootBase . 'uploads/' . $name, 'name' => $name]);
