<?php
// Folien-Vorlagen (Bibliothek). GET = Liste, POST {items:[...]} = speichern.
// Nur fuer angemeldete Admins.
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'data' => schauboard_read_dataset('templates')]);
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
    echo json_encode(['ok' => false, 'error' => 'Ungueltige Daten.']);
    exit;
}

$items = array_values($payload['items']);
if (!schauboard_write_dataset('templates', $items)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Speichern fehlgeschlagen (Schreibfehler auf data/).']);
    exit;
}

echo json_encode(['ok' => true, 'data' => $items]);
