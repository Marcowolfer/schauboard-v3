<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'data' => schauboard_read_dataset('settings')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungueltige JSON-Daten.']);
    exit;
}

$current = schauboard_read_dataset('settings');
$current['system']['timezone'] = schauboard_sanitize_text($payload['system']['timezone'] ?? $current['system']['timezone'] ?? 'Europe/Zurich');
$current['system']['language'] = schauboard_sanitize_text($payload['system']['language'] ?? $current['system']['language'] ?? 'de');
$current['system']['default_slide_duration'] = max(3, (int) ($payload['system']['default_slide_duration'] ?? $current['system']['default_slide_duration'] ?? 10));
$current['system']['default_transition'] = schauboard_sanitize_text($payload['system']['default_transition'] ?? $current['system']['default_transition'] ?? 'fade');
$current['weather']['enabled'] = schauboard_sanitize_bool($payload['weather']['enabled'] ?? $current['weather']['enabled'] ?? true);
$current['weather']['location'] = schauboard_sanitize_text($payload['weather']['location'] ?? $current['weather']['location'] ?? 'Zurich,CH');
$current['weather']['provider'] = schauboard_sanitize_text($payload['weather']['provider'] ?? $current['weather']['provider'] ?? 'wttr.in');
$current['maintenance']['enabled'] = schauboard_sanitize_bool($payload['maintenance']['enabled'] ?? $current['maintenance']['enabled'] ?? false);
$current['maintenance']['message'] = schauboard_sanitize_text($payload['maintenance']['message'] ?? $current['maintenance']['message'] ?? '');
$current['branding']['name'] = schauboard_sanitize_text($payload['branding']['name'] ?? $current['branding']['name'] ?? 'Schauboard');

schauboard_write_dataset('settings', $current);
echo json_encode(['ok' => true, 'data' => $current]);
