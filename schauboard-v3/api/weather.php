<?php
// Wetter-Proxy ueber Open-Meteo (kostenlos, kein API-Key, zuverlaessig).
// Loest den Ort per Geocoding auf (Geo-Ergebnis lange gecacht) und holt das
// aktuelle Wetter. Antwort 10 Min gecacht. Bei Ausfall wird ein alter Cache nur
// bis zu einer Maximaldauer weitergegeben - danach lieber "offline" als ein
// dauerhaft eingefrorener Falschwert.
// Kein Login: das Display ruft diesen Endpunkt direkt auf.
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cityRaw = preg_replace('/[^a-zA-Z0-9\-_\+ äöüÄÖÜéèàç\.,]/u', '', (string) ($_GET['city'] ?? 'Zurich'));
$cityRaw = trim($cityRaw);
if ($cityRaw === '') {
    echo json_encode(['error' => 'Kein Ort']);
    exit;
}

$cacheDir = dirname(__DIR__) . '/data/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/weather_' . md5($cityRaw) . '.json';
$cacheTtl = 600;          // 10 Min frisch
$staleMaxAge = 3 * 3600;  // alten Cache hoechstens 3h als Fallback ausgeben

// 1) Frischer Cache -> direkt ausliefern.
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    echo file_get_contents($cacheFile);
    exit;
}

// Kleiner JSON-GET-Helfer (nur HTTP 200 zaehlt als Erfolg).
$fetchJson = static function (string $url) {
    $ctx = stream_context_create(['http' => [
        'timeout' => 6,
        'user_agent' => 'Schauboard/3 (+https://schauboard.ch)',
        'header' => "Accept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    if ($status !== 0 && $status !== 200) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
};

// Bei Fehler: alten Cache nur bis $staleMaxAge weitergeben, sonst "offline".
$serveStaleOrError = static function () use ($cacheFile, $staleMaxAge) {
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $staleMaxAge) {
        echo file_get_contents($cacheFile);
    } else {
        echo json_encode(['error' => 'Wetter nicht verfügbar']);
    }
    exit;
};

// 2) Ort aufloesen (Geocoding) - Ergebnis 30 Tage cachen (Koordinaten aendern sich nicht).
$geoCache = $cacheDir . '/geo_' . md5($cityRaw) . '.json';
$geo = null;
if (is_file($geoCache) && (time() - filemtime($geoCache)) < 30 * 86400) {
    $geo = json_decode((string) file_get_contents($geoCache), true);
}
if (!is_array($geo) || !isset($geo['lat'], $geo['lon'])) {
    $parts = explode(',', $cityRaw, 2);
    $name = trim($parts[0]);
    $cc = isset($parts[1]) ? strtoupper(trim($parts[1])) : '';
    $geoData = $fetchJson('https://geocoding-api.open-meteo.com/v1/search?count=5&language=de&format=json&name=' . rawurlencode($name));
    $results = is_array($geoData) ? ($geoData['results'] ?? []) : [];
    if (!$results) {
        $serveStaleOrError();
    }
    // Falls Land angegeben (z. B. "Zurich,CH"), passenden Treffer bevorzugen.
    $pick = $results[0];
    if ($cc !== '') {
        foreach ($results as $r) {
            if (strtoupper((string) ($r['country_code'] ?? '')) === $cc) { $pick = $r; break; }
        }
    }
    $geo = [
        'lat' => $pick['latitude'] ?? null,
        'lon' => $pick['longitude'] ?? null,
        'name' => $pick['name'] ?? $name,
    ];
    if ($geo['lat'] === null || $geo['lon'] === null) {
        $serveStaleOrError();
    }
    @file_put_contents($geoCache, json_encode($geo));
}

// 3) Aktuelles Wetter + Tages-Vorhersage holen (heute + 3 Folgetage).
$wx = $fetchJson('https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string) $geo['lat'])
    . '&longitude=' . rawurlencode((string) $geo['lon'])
    . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m'
    . '&daily=weather_code,temperature_2m_max,temperature_2m_min&forecast_days=4&timezone=auto');
$cur = is_array($wx) ? ($wx['current'] ?? null) : null;
if (!is_array($cur) || !isset($cur['temperature_2m'])) {
    $serveStaleOrError();
}

// WMO-Wettercode -> Emoji + deutscher Text (fuer aktuell UND Vorhersage-Tage).
$wmo = static function (int $code): array {
    return match (true) {
        $code === 0 => ['☀️', 'Klar'],
        $code === 1 => ['🌤️', 'Überwiegend klar'],
        $code === 2 => ['⛅', 'Teils bewölkt'],
        $code === 3 => ['☁️', 'Bewölkt'],
        in_array($code, [45, 48], true) => ['🌫️', 'Nebel'],
        in_array($code, [51, 53, 55], true) => ['🌦️', 'Niesel'],
        in_array($code, [56, 57], true) => ['🌧️', 'Gefrierender Niesel'],
        in_array($code, [61, 63, 65], true) => ['🌧️', 'Regen'],
        in_array($code, [66, 67], true) => ['🌧️', 'Gefrierender Regen'],
        in_array($code, [71, 73, 75], true) => ['🌨️', 'Schnee'],
        $code === 77 => ['🌨️', 'Schneegriesel'],
        in_array($code, [80, 81, 82], true) => ['🌧️', 'Regenschauer'],
        in_array($code, [85, 86], true) => ['🌨️', 'Schneeschauer'],
        $code === 95 => ['⛈️', 'Gewitter'],
        in_array($code, [96, 99], true) => ['⛈️', 'Gewitter mit Hagel'],
        default => ['🌡️', ''],
    };
};

[$emoji, $desc] = $wmo((int) ($cur['weather_code'] ?? 0));

// 3-Tage-Vorschau (morgen + 2 Folgetage; Index 0 = heute wird uebersprungen).
$forecast = [];
$daily = is_array($wx) ? ($wx['daily'] ?? null) : null;
if (is_array($daily) && isset($daily['time']) && is_array($daily['time'])) {
    $dayNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    $count = count($daily['time']);
    for ($i = 1; $i < $count && count($forecast) < 3; $i++) {
        $ts = strtotime((string) $daily['time'][$i]);
        if ($ts === false) {
            continue;
        }
        [$dEmoji] = $wmo((int) ($daily['weather_code'][$i] ?? 0));
        $forecast[] = [
            'day' => $dayNames[(int) date('N', $ts) - 1],
            'emoji' => $dEmoji,
            'tmax' => isset($daily['temperature_2m_max'][$i]) ? (string) round((float) $daily['temperature_2m_max'][$i]) : '-',
            'tmin' => isset($daily['temperature_2m_min'][$i]) ? (string) round((float) $daily['temperature_2m_min'][$i]) : '-',
        ];
    }
}

$result = [
    'city' => $geo['name'] ?? $cityRaw,
    'temp_c' => (string) round((float) $cur['temperature_2m']),
    'feels_like' => isset($cur['apparent_temperature']) ? (string) round((float) $cur['apparent_temperature']) : '--',
    'desc' => $desc,
    'humidity' => (string) ($cur['relative_humidity_2m'] ?? '--'),
    'wind_kmph' => isset($cur['wind_speed_10m']) ? (string) round((float) $cur['wind_speed_10m']) : '--',
    'emoji' => $emoji,
    'forecast' => $forecast,
    'updated' => date('H:i'),
];

@file_put_contents($cacheFile, json_encode($result));
echo json_encode($result);
