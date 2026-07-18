<?php
// RSS-/Atom-Proxy fuer den Nachrichten-Block. Holt einen Feed, parst ihn zu
// einer kompakten JSON-Liste (Titel + Zeitstempel) und cacht die Antwort
// 10 Min. Bei Ausfall wird ein alter Cache nur bis zu einer Maximaldauer
// weitergegeben - danach lieber "offline" als eingefrorene Schlagzeilen.
//
// Kein Login fuer Displays - ABER kein offener Proxy: ohne Admin-Session
// werden nur URLs geholt, die in einem GESPEICHERTEN RSS-Block stehen.
// (Der Editor laeuft mit Admin-Session und darf frei testen.)
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$url = schauboard_sanitize_http_url($_GET['url'] ?? '');
if ($url === '') {
    echo json_encode(['error' => 'Keine gueltige Feed-URL']);
    exit;
}

// Zugriffs-Check (SSRF-Schutz): Admin-Session ODER URL steht in einem
// gespeicherten RSS-Block.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$allowed = schauboard_session_is_authenticated();
if (!$allowed) {
    foreach (schauboard_read_dataset('slides') as $slide) {
        foreach (($slide['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'rss' && ($block['url'] ?? '') === $url) {
                $allowed = true;
                break 2;
            }
        }
    }
}
if (!$allowed) {
    echo json_encode(['error' => 'Feed-URL nicht freigegeben']);
    exit;
}

$cacheDir = dirname(__DIR__) . '/data/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/rss_' . md5($url) . '.json';
$cacheTtl = 600;          // 10 Min frisch
$staleMaxAge = 3 * 3600;  // alten Cache hoechstens 3h als Fallback ausgeben

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    echo file_get_contents($cacheFile);
    exit;
}

$serveStaleOrError = static function () use ($cacheFile, $staleMaxAge) {
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $staleMaxAge) {
        echo file_get_contents($cacheFile);
    } else {
        echo json_encode(['error' => 'Feed nicht verfuegbar']);
    }
    exit;
};

// SSRF-Haertung: Loopback/Link-Local ist generell tabu (127.x, ::1, localhost,
// 169.254.x - dort leben lokale Dienste und Cloud-Metadata-Endpoints). Private
// LAN-Adressen bleiben ERLAUBT: Schauboard ist selbst eine LAN-App und
// Intranet-Feeds sind ein legitimer Anwendungsfall.
$isBlockedTarget = static function (string $checkUrl): bool {
    $host = strtolower((string) parse_url($checkUrl, PHP_URL_HOST));
    if ($host === '') {
        return true;
    }
    $host = trim($host, '[]'); // IPv6-Literal [::1]
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return true;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolved = @gethostbyname($host); // liefert bei Fehlschlag den Hostnamen zurueck
        if ($resolved !== $host) {
            $ips[] = $resolved;
        }
    }
    foreach ($ips as $ip) {
        $ip = strtolower($ip);
        if (str_starts_with($ip, '127.') || $ip === '::1' || str_starts_with($ip, '169.254.') || str_starts_with($ip, 'fe80:')) {
            return true;
        }
    }
    return false;
};

// Feed holen (nur HTTP 200, Groesse begrenzt). Redirects werden MANUELL
// verfolgt (max. 3 Hops), damit JEDES Ziel erneut geprueft wird - ein
// freigegebener Feed darf nicht per 302 auf interne Dienste umleiten.
$fetchFeed = static function (string $feedUrl) use ($isBlockedTarget) {
    for ($hop = 0; $hop <= 3; $hop++) {
        if (!preg_match('#^https?://#i', $feedUrl) || $isBlockedTarget($feedUrl)) {
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'user_agent' => 'Schauboard/3 (+https://schauboard.ch)',
            'header' => "Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($feedUrl, false, $ctx, 0, 3 * 1024 * 1024);
        if ($raw === false) {
            return null;
        }
        $status = 0;
        $location = '';
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
                $location = '';
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            if (!preg_match('#^https?://#i', $location)) {
                // Relativen Redirect gegen den aktuellen Host aufloesen.
                $p = parse_url($feedUrl);
                $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
                $location = str_starts_with($location, '/') ? $base . $location : $base . '/' . $location;
            }
            $feedUrl = $location;
            continue;
        }
        if ($status !== 0 && $status !== 200) {
            return null;
        }
        return $raw !== '' ? $raw : null;
    }
    return null; // zu viele Redirects
};

$raw = $fetchFeed($url);
if ($raw === null) {
    $serveStaleOrError();
}

// XML parsen (RSS 2.0, Atom, RSS 1.0/RDF). LIBXML_NONET: keine externen
// Ressourcen nachladen (XXE-Schutz).
libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
if ($xml === false) {
    $serveStaleOrError();
}

$cleanTitle = static function ($value): string {
    $t = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return function_exists('mb_substr') ? mb_substr($t, 0, 300) : substr($t, 0, 300);
};
$parseTs = static function ($value): ?int {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : $ts;
};

$sourceTitle = '';
$nodes = null;
$isAtom = false;

if (isset($xml->channel->item) && count($xml->channel->item)) {
    // RSS 2.0 (und 0.9x): item haengt UNTER channel. Achtung: bei RSS 1.0/RDF
    // existiert channel auch, aber item liegt daneben auf Root-Ebene -> deshalb
    // hier explizit auf channel->item pruefen, nicht nur auf channel.
    $sourceTitle = $cleanTitle($xml->channel->title ?? '');
    $nodes = $xml->channel->item;
} else {
    // Atom: <feed> mit Default-Namespace; RSS 1.0/RDF: <rdf:RDF> mit item auf Root-Ebene.
    $atom = $xml->children('http://www.w3.org/2005/Atom');
    if (isset($atom->entry) && count($atom->entry)) {
        $sourceTitle = $cleanTitle($atom->title ?? '');
        $nodes = $atom->entry;
        $isAtom = true;
    } elseif (isset($xml->entry) && count($xml->entry)) {
        $sourceTitle = $cleanTitle($xml->title ?? '');
        $nodes = $xml->entry;
        $isAtom = true;
    } else {
        $rss1 = $xml->children('http://purl.org/rss/1.0/');
        if (isset($rss1->item) && count($rss1->item)) {
            $sourceTitle = $cleanTitle($rss1->channel->title ?? '');
            $nodes = $rss1->item;
        } elseif (isset($xml->item) && count($xml->item)) {
            $sourceTitle = $cleanTitle($xml->channel->title ?? '');
            $nodes = $xml->item;
        }
    }
}

if ($nodes === null || !count($nodes)) {
    $serveStaleOrError();
}

$items = [];
foreach ($nodes as $node) {
    $title = $cleanTitle($node->title ?? '');
    if ($title === '') {
        continue;
    }
    if ($isAtom) {
        $ts = $parseTs($node->published ?? '') ?? $parseTs($node->updated ?? '');
    } else {
        $ts = $parseTs($node->pubDate ?? '');
        if ($ts === null) {
            // RSS 1.0: Datum steckt in dc:date
            $dc = $node->children('http://purl.org/dc/elements/1.1/');
            $ts = $parseTs($dc->date ?? '');
        }
    }
    $items[] = ['title' => $title, 'ts' => $ts];
    if (count($items) >= 15) {
        break;
    }
}

if ($items === []) {
    $serveStaleOrError();
}

$result = [
    'source' => $sourceTitle,
    'items' => $items,
    'updated' => date('H:i'),
];

@file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
