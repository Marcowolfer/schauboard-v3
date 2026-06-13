<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

session_start();
schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['slide']) || !is_array($payload['slide'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Keine Folie zum Vorschauen.']);
    exit;
}

// Entwurf in der Session ablegen – das Display rendert ihn ueber ?preview=1
// mit exakt derselben Engine wie das echte TV-Bild.
$_SESSION['schauboard_preview'] = ['slide' => $payload['slide']];

echo json_encode(['ok' => true]);
