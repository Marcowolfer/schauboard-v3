<?php
// Wetter-Proxy: holt Daten von wttr.in, cached 10 Minuten.
// Kein Login: das Display ruft diesen Endpunkt direkt auf.
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$city = preg_replace('/[^a-zA-Z0-9\-_\+ äöüÄÖÜ,.]/u', '', (string) ($_GET['city'] ?? 'Zurich'));
$city = trim($city);
if ($city === '') {
    echo json_encode(['error' => 'Kein Ort']);
    exit;
}

$cacheDir = dirname(__DIR__) . '/data/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/weather_' . md5($city) . '.json';
$cacheTtl = 600;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    echo file_get_contents($cacheFile);
    exit;
}

$url = 'https://wttr.in/' . rawurlencode($city) . '?format=j1&lang=de';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'Schauboard/3.0']]);
$raw = @file_get_contents($url, false, $ctx);

if (!$raw) {
    if (is_file($cacheFile)) {
        echo file_get_contents($cacheFile);
        exit;
    }
    echo json_encode(['error' => 'Wetter nicht verfügbar']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'Parse-Fehler']);
    exit;
}

$cur = $data['current_condition'][0] ?? [];
$area = $data['nearest_area'][0] ?? [];
$code = (int) ($cur['weatherCode'] ?? 113);
$emoji = match (true) {
    $code === 113 => '☀️',
    $code === 116 => '⛅',
    in_array($code, [119, 122], true) => '☁️',
    in_array($code, [143, 248, 260], true) => '🌫️',
    in_array($code, [176, 263, 266, 293, 296, 299, 302, 305, 308, 353, 356, 359], true) => '🌧️',
    in_array($code, [179, 182, 185, 281, 284, 311, 314, 317, 320, 323, 326, 329, 332, 335, 338, 350, 368, 371, 374, 377], true) => '🌨️',
    in_array($code, [200, 386, 389, 392, 395], true) => '⛈️',
    default => '🌡️',
};

$result = [
    'city' => $area['areaName'][0]['value'] ?? $city,
    'temp_c' => $cur['temp_C'] ?? '--',
    'feels_like' => $cur['FeelsLikeC'] ?? '--',
    'desc' => $cur['lang_de'][0]['value'] ?? $cur['weatherDesc'][0]['value'] ?? '',
    'humidity' => $cur['humidity'] ?? '--',
    'wind_kmph' => $cur['windspeedKmph'] ?? '--',
    'emoji' => $emoji,
    'updated' => date('H:i'),
];

@file_put_contents($cacheFile, json_encode($result));
echo json_encode($result);
