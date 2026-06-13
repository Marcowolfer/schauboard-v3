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

// Struktur gegen die Defaults absichern: ein (extern) beschaedigtes settings.json
// mit nicht-Array-Sektionen wuerde sonst einen Fatal ausloesen.
$current = schauboard_read_dataset('settings');
foreach (['system', 'weather', 'maintenance', 'branding'] as $sec) {
    if (!isset($current[$sec]) || !is_array($current[$sec])) {
        $current[$sec] = schauboard_settings_defaults()[$sec];
    }
}
$current['system']['timezone'] = schauboard_sanitize_text($payload['system']['timezone'] ?? $current['system']['timezone'] ?? 'Europe/Zurich');
$current['system']['language'] = schauboard_sanitize_text($payload['system']['language'] ?? $current['system']['language'] ?? 'de');
$current['system']['default_slide_duration'] = max(3, (int) ($payload['system']['default_slide_duration'] ?? $current['system']['default_slide_duration'] ?? 10));
$current['system']['default_transition'] = schauboard_sanitize_text($payload['system']['default_transition'] ?? $current['system']['default_transition'] ?? 'fade');
$current['system']['offline_timeout_minutes'] = max(1, min(120, (int) ($payload['system']['offline_timeout_minutes'] ?? $current['system']['offline_timeout_minutes'] ?? 5)));
$current['weather']['enabled'] = schauboard_sanitize_bool($payload['weather']['enabled'] ?? $current['weather']['enabled'] ?? true);
$current['weather']['location'] = schauboard_sanitize_text($payload['weather']['location'] ?? $current['weather']['location'] ?? 'Zurich,CH');
$current['weather']['provider'] = schauboard_sanitize_text($payload['weather']['provider'] ?? $current['weather']['provider'] ?? 'wttr.in');
$current['maintenance']['enabled'] = schauboard_sanitize_bool($payload['maintenance']['enabled'] ?? $current['maintenance']['enabled'] ?? false);
$current['maintenance']['message'] = schauboard_sanitize_text($payload['maintenance']['message'] ?? $current['maintenance']['message'] ?? '');
$current['branding']['name'] = schauboard_sanitize_text($payload['branding']['name'] ?? $current['branding']['name'] ?? 'Schauboard');

if (!schauboard_write_dataset('settings', $current)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Speichern fehlgeschlagen (Schreibfehler auf data/).']);
    exit;
}
echo json_encode(['ok' => true, 'data' => $current]);
