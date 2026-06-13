<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_ensure_data_files();

$settings = schauboard_read_dataset('settings');
$slides = schauboard_read_dataset('slides');
$playlists = schauboard_read_dataset('playlists');
$displays = schauboard_read_dataset('displays');
$schedules = schauboard_read_dataset('schedules');

$isPreview = isset($_GET['preview']);

$requestedDisplayId = schauboard_sanitize_id($_GET['display'] ?? 'default', 'default');
$display = schauboard_find_by_id($displays, $requestedDisplayId) ?? ($displays[0] ?? null);
$displayId = $display['id'] ?? 'default';
$playlistId = $display['default_playlist_id'] ?? 'playlist_default';

// Zeitsteuerung: aktives Zeitfenster ueberschreibt die Standard-Playlist.
$timezone = $settings['system']['timezone'] ?? 'Europe/Zurich';
try {
    $now = new DateTime('now', new DateTimeZone($timezone));
} catch (Exception $e) {
    $now = new DateTime('now');
}
$dayMap = ['Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed', 'Thu' => 'thu', 'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun'];
$dayKey = $dayMap[$now->format('D')] ?? 'mon';
$currentTime = $now->format('H:i');

foreach ($schedules as $candidate) {
    if (($candidate['display_id'] ?? '') !== $displayId) {
        continue;
    }
    $days = $candidate['days'] ?? [];
    if ($days !== [] && !in_array($dayKey, $days, true)) {
        continue;
    }
    $from = (string) ($candidate['from'] ?? '00:00');
    $to = (string) ($candidate['to'] ?? '23:59');
    if ($currentTime >= $from && $currentTime <= $to) {
        $playlistId = $candidate['playlist_id'] ?? $playlistId;
        break;
    }
}

// Vorschau aus dem Editor: ?preview=1 zeigt genau eine uebergebene Folie
// (aus der Session abgelegter Entwurf), ohne Rotation/Heartbeat.
$activeSlides = [];
$maintenance = !empty($settings['maintenance']['enabled']);
$maintenanceMessage = (string) ($settings['maintenance']['message'] ?? '');

if ($isPreview) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $draft = $_SESSION['schauboard_preview'] ?? null;
    if (is_array($draft) && isset($draft['slide']) && is_array($draft['slide'])) {
        $activeSlides = [schauboard_sanitize_slide($draft['slide'])];
    }
    $maintenance = false;
} else {
    $playlist = schauboard_find_by_id($playlists, $playlistId) ?? ($playlists[0] ?? null);
    foreach (($playlist['slide_ids'] ?? []) as $slideId) {
        $slide = schauboard_find_by_id($slides, (string) $slideId);
        if ($slide !== null) {
            $activeSlides[] = $slide;
        }
    }
}

// Pfad zum Web-Root (fuer api/*-Aufrufe), egal ob via / oder /display/ aufgerufen.
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if (substr($scriptDir, -8) === '/display') {
    $scriptDir = substr($scriptDir, 0, -8);
}
$rootBase = ($scriptDir === '' || $scriptDir === '.') ? '/' : rtrim($scriptDir, '/') . '/';

$displayConfig = [
    'displayId' => $displayId,
    'slides' => $activeSlides,
    'transition' => $settings['system']['default_transition'] ?? 'fade',
    'defaultDuration' => (int) ($settings['system']['default_slide_duration'] ?? 10),
    'weatherEndpoint' => ($settings['weather']['enabled'] ?? true) ? $rootBase . 'api/weather.php' : '',
    'heartbeatEndpoint' => $rootBase . 'api/heartbeat.php',
    'revisionEndpoint' => $rootBase . 'api/revision.php',
    'revision' => schauboard_revision(),
    'preview' => $isPreview,
    'emptyMessage' => 'Keine aktive Folie – bitte im Admin eine Playlist zuweisen.',
];

$pageTitle = $maintenance ? 'Wartung' : ($display['name'] ?? 'Schauboard Display');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($rootBase) ?>assets/blocks.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden;background:#02060d;font-family:"Segoe UI",Arial,sans-serif}
.display-shell{position:fixed;inset:0;display:grid;place-items:center;background:#02060d}
#sbStage{position:relative;width:min(100vw, calc(100vh * 16 / 9));height:min(100vh, calc(100vw * 9 / 16));overflow:hidden;background:#101828}
.sb-empty{position:absolute;inset:0;display:grid;place-items:center;color:rgba(255,255,255,.62);font-size:30px;letter-spacing:-.02em;text-align:center;padding:40px}
.maintenance{position:fixed;inset:0;display:grid;place-items:center;background:radial-gradient(circle at 50% 30%, #18233b, #060b16);color:#e7eefc;text-align:center;padding:40px}
.maintenance h1{font-size:42px;letter-spacing:-.03em;margin-bottom:14px}
.maintenance p{color:#9fb0c9;font-size:20px;max-width:760px;line-height:1.5}
</style>
</head>
<body>
<?php if ($maintenance): ?>
  <div class="maintenance">
    <div>
      <h1>🛠️ Wartung</h1>
      <p><?= htmlspecialchars($maintenanceMessage !== '' ? $maintenanceMessage : 'Dieses Display ist gerade im Wartungsmodus.') ?></p>
    </div>
  </div>
<?php else: ?>
  <div class="display-shell">
    <main id="sbStage"></main>
  </div>
  <script>window.SCHAUBOARD_DISPLAY = <?= json_encode($displayConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
  <script src="<?= htmlspecialchars($rootBase) ?>assets/blocks.js"></script>
  <script src="<?= htmlspecialchars($rootBase) ?>assets/display.js"></script>
<?php endif; ?>
</body>
</html>
