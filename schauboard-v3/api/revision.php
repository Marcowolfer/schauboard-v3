<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Display-spezifische Revision: aendert sich auch, wenn ein Zeitfenster die
// aktive Playlist wechselt oder eine Folie ihren Gueltigkeitszeitraum
// betritt/verlaesst (ohne Datei-Aenderung). So erkennt der 5s-Poll des
// Displays auch reine Tageszeit-/Datums-Umschaltungen und laedt neu.
$displays = schauboard_read_dataset('displays');
$schedules = schauboard_read_dataset('schedules');
$settings = schauboard_read_dataset('settings');
$playlists = schauboard_read_dataset('playlists');
$slides = schauboard_read_dataset('slides');

$displayId = schauboard_sanitize_id($_GET['display'] ?? 'default', 'default');
$display = schauboard_find_by_id($displays, $displayId)
    ?? ($displays[0] ?? ['id' => 'default', 'default_playlist_id' => 'playlist_default']);

$timezone = $settings['system']['timezone'] ?? 'Europe/Zurich';
try {
    $now = new DateTime('now', new DateTimeZone($timezone));
} catch (Exception $e) {
    $now = new DateTime('now');
}

echo json_encode(['ok' => true, 'revision' => schauboard_display_revision($display, $schedules, $playlists, $slides, $now)]);
