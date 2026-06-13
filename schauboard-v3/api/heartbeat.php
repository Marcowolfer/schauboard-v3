<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Heartbeat ist bewusst ohne Login: das Display selbst meldet sich.
// Status liegt in einer eigenen Datei (data/heartbeats.json) und NICHT in
// displays.json – sonst wuerde jeder Heartbeat die Revision aendern und alle
// Displays im Minutentakt neu laden.
$displayId = schauboard_sanitize_id($_GET['display'] ?? 'default', 'default');

$path = dirname(__DIR__) . '/data/heartbeats.json';
$beats = schauboard_read_json_file($path, []);
$beats[$displayId] = date('c');
schauboard_write_json_file($path, $beats);

echo json_encode(['ok' => true]);
