<?php
// Komplett-Backup fuer Sicherung/Umzug.
//   GET  -> liefert alle Inhalte als eine Export-Datei (Envelope).
//   POST {data:{...}} -> spielt ein Backup ein (ERSETZT die enthaltenen Datensaetze).
// Nur fuer angemeldete Admins.
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

$datasets = ['slides', 'playlists', 'displays', 'schedules', 'settings', 'templates'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = [];
    foreach ($datasets as $name) {
        $data[$name] = schauboard_read_dataset($name);
    }
    echo json_encode([
        'schauboard' => true,
        'kind' => 'backup',
        'format' => 1,
        'version' => schauboard_version()['current'] ?? '',
        'exported_at' => date('c'),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$data = is_array($payload) ? ($payload['data'] ?? null) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungueltiges Backup.']);
    exit;
}

// Mindestens ein bekannter Datensatz muss enthalten sein.
$found = array_intersect($datasets, array_keys($data));
if (!$found) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Backup enthaelt keine bekannten Daten.']);
    exit;
}

$written = [];
foreach ($datasets as $name) {
    if (!isset($data[$name]) || !is_array($data[$name])) {
        continue;
    }
    if (!schauboard_write_dataset($name, $data[$name])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Schreibfehler bei "' . $name . '" (Teil-Import: ' . implode(', ', $written) . ').']);
        exit;
    }
    $written[] = $name;
}

echo json_encode(['ok' => true, 'written' => $written]);
