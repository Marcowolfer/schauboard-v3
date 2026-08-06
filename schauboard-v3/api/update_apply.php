<?php
// In-App-Update ausfuehren: ZIP laden, sha256 pruefen, Programmdateien tauschen
// (mit Backup + Auto-Rollback). data/, uploads/, config.local.php bleiben in Ruhe.
// Nur fuer angemeldete Admins, nur per POST.
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => t('api.method_not_allowed', 'Methode nicht erlaubt.')]);
    exit;
}

// Laenger laufen lassen: Download + Entpacken + Kopieren kann etwas dauern.
@set_time_limit(120);

$res = schauboard_apply_update();
echo json_encode($res);
