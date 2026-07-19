<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Heartbeat ist bewusst ohne Login: das Display selbst meldet sich.
// Status liegt in einer eigenen Datei (data/heartbeats.json) und NICHT in
// displays.json – sonst wuerde jeder Heartbeat die Revision aendern und alle
// Displays im Minutentakt neu laden.
// Laenge zusaetzlich begrenzen: der Endpunkt ist bewusst ohne Login, ein langer
// oder frei erfundener Parameter darf die Datei nicht aufblaehen.
$displayId = substr(schauboard_sanitize_id($_GET['display'] ?? 'default', 'default'), 0, 64);

$path = dirname(__DIR__) . '/data/heartbeats.json';
$beats = schauboard_read_json_file($path, []);
$beats[$displayId] = date('c');

// Aufraeumen: nur Heartbeats WIRKLICH konfigurierter Displays behalten (plus das
// gerade gemeldete). Sonst waechst die Datei durch Tippfehler in der Display-URL
// oder Fremdaufrufe unbegrenzt - und admin/index.php laedt sie in jede Seite.
// Bindet zugleich geloeschte Displays aus (die sonst nie geprunt wuerden).
$known = [$displayId => true];
foreach (schauboard_read_dataset('displays') as $d) {
    if (isset($d['id'])) {
        $known[(string) $d['id']] = true;
    }
}
$beats = array_intersect_key($beats, $known);

schauboard_write_json_file($path, $beats);

echo json_encode(['ok' => true]);
