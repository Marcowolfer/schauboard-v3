<?php

/*
 * Update-Pruefung fuer Schauboard.
 *
 * Bewusst NUR "Hinweis + gefuehrter Download": die App vergleicht ihre lokale
 * Version mit einem Manifest auf der Projekt-Website und zeigt im Admin einen
 * Banner an. Es wird NICHTS automatisch heruntergeladen oder ueberschrieben.
 *
 * Manifest-Format (JSON):
 *   { "version": "3.1.0", "url": "https://schauboard.ch/dl/download.php",
 *     "sha256": "...", "date": "2026-07-01", "notes": "Kurztext" }
 */

function schauboard_update_cache_file(): string
{
    return dirname(__DIR__) . '/data/update_check.json';
}

// Manifest-URL aus der Config (in config.local.php ueberschreibbar).
function schauboard_update_manifest_url(): string
{
    $cfg = schauboard_config();
    return trim((string) ($cfg['update_manifest_url'] ?? ''));
}

function schauboard_update_enabled(): bool
{
    $cfg = schauboard_config();
    return !empty($cfg['update_check_enabled']) && schauboard_update_manifest_url() !== '';
}

// Nur https-URLs mit Host durchlassen (kein javascript:, kein http:) – die URL
// landet ungefiltert als Link-Ziel im Admin.
function schauboard_update_safe_url($url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        return '';
    }
    return $url;
}

// Liefert: enabled, current, latest, update_available, url, notes, date, checked_at, source.
function schauboard_check_update(bool $force = false): array
{
    $current = (string) (schauboard_version()['current'] ?? '0.0.0');

    if (!schauboard_update_enabled()) {
        return [
            'enabled' => false,
            'current' => $current,
            'latest' => null,
            'update_available' => false,
            'url' => '',
            'notes' => '',
            'date' => '',
            'checked_at' => null,
            'source' => 'disabled',
        ];
    }

    $cacheFile = schauboard_update_cache_file();
    $ttl = 12 * 3600;

    // Frischer Cache -> nicht erneut ueber das Netz gehen.
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['latest'])) {
            return schauboard_update_decorate($cached, $current, 'cache');
        }
    }

    // Live abfragen.
    $ctx = stream_context_create(['http' => [
        'timeout' => 6,
        'user_agent' => 'Schauboard/' . $current . ' (update-check)',
        'header' => "Accept: application/json\r\n",
    ]]);
    $raw = @file_get_contents(schauboard_update_manifest_url(), false, $ctx);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($manifest) || empty($manifest['version'])) {
        // Fehlgeschlagen -> alten Cache nehmen, sonst leeres (aber gueltiges) Ergebnis.
        if (is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['latest'])) {
                return schauboard_update_decorate($cached, $current, 'cache');
            }
        }
        return [
            'enabled' => true,
            'current' => $current,
            'latest' => null,
            'update_available' => false,
            'url' => '',
            'notes' => '',
            'date' => '',
            'checked_at' => null,
            'source' => 'error',
        ];
    }

    $info = [
        'latest' => (string) $manifest['version'],
        'url' => schauboard_update_safe_url($manifest['url'] ?? ''),
        'notes' => (string) ($manifest['notes'] ?? ''),
        'date' => (string) ($manifest['date'] ?? ''),
        'checked_at' => date('c'),
    ];
    @schauboard_write_file($cacheFile, json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return schauboard_update_decorate($info, $current, 'live');
}

function schauboard_update_decorate(array $info, string $current, string $source): array
{
    $latest = (string) ($info['latest'] ?? '');
    return [
        'enabled' => true,
        'current' => $current,
        'latest' => $latest,
        'update_available' => $latest !== '' && version_compare($latest, $current, '>'),
        'url' => schauboard_update_safe_url($info['url'] ?? ''),
        'notes' => (string) ($info['notes'] ?? ''),
        'date' => (string) ($info['date'] ?? ''),
        'checked_at' => $info['checked_at'] ?? null,
        'source' => $source,
    ];
}
