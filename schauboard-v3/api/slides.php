<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'data' => schauboard_read_dataset('slides')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungueltige JSON-Daten.']);
    exit;
}

$items = array_map('schauboard_sanitize_slide', $payload['items']);
schauboard_write_dataset('slides', array_values($items));
echo json_encode(['ok' => true, 'data' => $items]);
