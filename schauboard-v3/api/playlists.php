<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'data' => schauboard_read_dataset('playlists')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => t('api.method_not_allowed', 'Methode nicht erlaubt.')]);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => t('api.invalid_json', 'Ungueltige JSON-Daten.')]);
    exit;
}

$items = array_values(array_map('schauboard_sanitize_playlist', $payload['items']));
if (!schauboard_write_dataset('playlists', $items)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => t('api.save_failed', 'Speichern fehlgeschlagen (Schreibfehler auf data/).')]);
    exit;
}
echo json_encode(['ok' => true, 'data' => $items]);
