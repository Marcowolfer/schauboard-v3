<?php

/*
 * Update-Pruefung + In-App-Update fuer Schauboard.
 *
 * Die App vergleicht ihre lokale Version mit einem Manifest auf der Projekt-
 * Website und zeigt im Admin einen Banner. Update geht auf zwei Arten:
 *  - "Jetzt aktualisieren" (In-App): laedt das ZIP, prueft die sha256, sichert
 *    den alten Stand und tauscht NUR die Programmdateien aus (mit Auto-Rollback).
 *    data/, uploads/ und config.local.php werden NIE angefasst.
 *  - Fallback "Herunterladen": gefuehrter manueller Download (wenn der Server
 *    seine eigenen Dateien nicht ueberschreiben darf -> Preflight entscheidet).
 *
 * Manifest-Format (JSON):
 *   { "version": "3.1.3", "url": "https://schauboard.ch/dl/download.php",
 *     "zip": "https://schauboard.ch/dl/files/schauboard-v3.1.3.zip",
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

// Host, von dem ausschliesslich geladen werden darf = Host des Manifests
// (z. B. schauboard.ch). So kann ein manipuliertes Manifest den Download nicht
// auf eine fremde Domain umbiegen (Host-Pinning).
function schauboard_update_allowed_host(): string
{
    $p = parse_url(schauboard_update_manifest_url());
    return strtolower((string) ($p['host'] ?? ''));
}

// Nur https-URLs auf dem erlaubten Host durchlassen (kein javascript:, kein
// http:, keine fremde Domain). Die URL landet als Link-Ziel/Download-Quelle.
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
    $allowed = schauboard_update_allowed_host();
    if ($allowed !== '' && strtolower($p['host']) !== $allowed) {
        return ''; // Host-Pinning: nur der Manifest-Host ist erlaubt
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
        'zip' => schauboard_update_safe_url($manifest['zip'] ?? ''),
        'sha256' => strtolower(trim((string) ($manifest['sha256'] ?? ''))),
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
        'zip' => schauboard_update_safe_url($info['zip'] ?? ''),
        'sha256' => strtolower((string) ($info['sha256'] ?? '')),
        'notes' => (string) ($info['notes'] ?? ''),
        'date' => (string) ($info['date'] ?? ''),
        'checked_at' => $info['checked_at'] ?? null,
        'source' => $source,
    ];
}

/* ===== In-App-Update (Auto) ============================================== */

function schauboard_app_root(): string
{
    return dirname(__DIR__);
}

// Kann sich die Installation selbst aktualisieren? (ZipArchive + Schreibrechte)
function schauboard_update_can_auto(): array
{
    $reasons = [];
    if (!class_exists('ZipArchive')) {
        $reasons[] = t('update.reason.no_ziparchive', 'PHP-ZipArchive fehlt');
    }
    $root = schauboard_app_root();
    if (!is_writable($root) || !is_writable($root . '/core') || !is_writable($root . '/admin')) {
        $reasons[] = t('update.reason.not_writable', 'Programmdateien sind nicht beschreibbar');
    }
    return ['ok' => $reasons === [], 'reasons' => $reasons];
}

function schauboard_update_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((scandir($dir) ?: []) as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            schauboard_update_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

// Alle Dateien (relative Pfade) unterhalb von $dir.
function schauboard_update_list_files(string $dir, string $base = ''): array
{
    $out = [];
    foreach ((scandir($dir) ?: []) as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $rel = $base === '' ? $it : $base . '/' . $it;
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            $out = array_merge($out, schauboard_update_list_files($p, $rel));
        } else {
            $out[] = $rel;
        }
    }
    return $out;
}

// Kopiert das entpackte Paket ($srcRoot) ueber die Installation ($appRoot), mit
// Backup jeder ueberschriebenen Datei (fuer Rollback). config.local.php bleibt
// unangetastet; Live-Daten (data/*.json) sind im Paket gar nicht enthalten.
// Bei Fehler wird automatisch komplett zurueckgerollt.
function schauboard_update_install_files(string $srcRoot, string $appRoot, string $backupRoot, ?string &$error = null): bool
{
    $files = schauboard_update_list_files($srcRoot);
    schauboard_update_rrmdir($backupRoot);
    if (!@mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
        $error = t('update.error.backup_dir', 'Backup-Ordner nicht anlegbar');
        return false;
    }

    $done = []; // [rel, warNeu]
    $rollback = static function () use (&$done, $appRoot, $backupRoot) {
        foreach (array_reverse($done) as [$rel, $wasNew]) {
            $dst = $appRoot . '/' . $rel;
            $bak = $backupRoot . '/' . $rel;
            if ($wasNew) {
                @unlink($dst);
            } elseif (is_file($bak)) {
                @copy($bak, $dst);
            }
        }
    };

    foreach ($files as $rel) {
        // Nutzerdaten & echte Konfiguration NIE ueberschreiben - auch nicht, wenn
        // sie versehentlich ins Release-Paket geraten (Live-Daten liegen nur auf
        // dem Server; im Paket sind data/*.json gar nicht enthalten, aber der
        // Filter schuetzt zusaetzlich gegen einen Fehler beim ZIP-Bauen).
        if ($rel === 'config.local.php'
            || strncmp($rel, 'data/', 5) === 0
            || strncmp($rel, 'uploads/', 8) === 0) {
            continue;
        }
        $src = $srcRoot . '/' . $rel;
        $dst = $appRoot . '/' . $rel;
        $existed = is_file($dst);

        if ($existed) {
            $bak = $backupRoot . '/' . $rel;
            if ((!is_dir(dirname($bak)) && !@mkdir(dirname($bak), 0775, true)) || !@copy($dst, $bak)) {
                $error = t('update.error.backup_failed', 'Backup fehlgeschlagen: {file}', ['file' => $rel]);
                $rollback();
                return false;
            }
        } else {
            $d = dirname($dst);
            if (!is_dir($d) && !@mkdir($d, 0775, true)) {
                $error = t('update.error.dir_failed', 'Ordner nicht anlegbar: {file}', ['file' => $rel]);
                $rollback();
                return false;
            }
        }

        if (!@copy($src, $dst)) {
            $error = t('update.error.copy_failed', 'Kopieren fehlgeschlagen: {file}', ['file' => $rel]);
            // Die gerade (evtl. schon auf 0 Byte trunkierte) Zieldatei steht noch
            // NICHT in $done -> der $rollback() unten wuerde genau sie ueberspringen
            // und eine kaputte PHP-Datei stehen lassen (Fatal auf jeder Seite).
            // Deshalb hier zuerst selbst zuruecksetzen: existierte sie, aus dem
            // intakten Backup; war sie neu, das Fragment entfernen.
            if ($existed) {
                @copy($backupRoot . '/' . $rel, $dst);
            } else {
                @unlink($dst);
            }
            $rollback();
            return false;
        }
        $done[] = [$rel, !$existed];
    }

    return true;
}

// Fuehrt das komplette In-App-Update aus. Rueckgabe: ok / version / error.
function schauboard_apply_update(): array
{
    $can = schauboard_update_can_auto();
    if (!$can['ok']) {
        return ['ok' => false, 'error' => t('update.error.auto_not_possible', 'Automatisches Update nicht möglich: {reasons}. Bitte manuell aktualisieren.', ['reasons' => implode(', ', $can['reasons'])])];
    }

    $info = schauboard_check_update(true); // frisches Manifest
    if (empty($info['update_available'])) {
        return ['ok' => false, 'error' => t('update.error.no_update', 'Kein Update verfügbar.')];
    }
    $current = (string) (schauboard_version()['current'] ?? '0.0.0');

    // Nur die direkte, auf den Manifest-Host gepinnte zip-URL verwenden
    // (kein Redirect auf fremde Domains -> echtes Host-Pinning).
    $zipUrl = schauboard_update_safe_url($info['zip'] ?? '');
    if ($zipUrl === '') {
        return ['ok' => false, 'error' => t('update.error.no_zip', 'Kein direkter Download (zip) auf {host} im Manifest – bitte manuell aktualisieren.', ['host' => schauboard_update_allowed_host()])];
    }
    $expectSha = strtolower((string) ($info['sha256'] ?? ''));

    $dataDir = dirname(schauboard_json_path('settings'));
    $work = $dataDir . '/update_tmp';
    schauboard_update_rrmdir($work);
    if (!@mkdir($work, 0775, true) && !is_dir($work)) {
        return ['ok' => false, 'error' => t('update.error.workdir', 'Arbeitsordner nicht anlegbar (ist data/ beschreibbar?).')];
    }

    // 1) Herunterladen – bewusst OHNE Redirects, damit der Download den
    //    gepinnten Host nicht verlassen kann.
    $ctx = stream_context_create(['http' => [
        'timeout' => 45,
        'follow_location' => 0,
        'user_agent' => 'Schauboard/' . $current . ' (auto-update)',
    ]]);
    $bytes = @file_get_contents($zipUrl, false, $ctx);
    if ($bytes === false || strlen($bytes) < 1000) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.download', 'Download fehlgeschlagen.')];
    }

    // 2) Integritaet pruefen (sha256 aus dem Manifest, kommt per https von schauboard.ch)
    if ($expectSha !== '') {
        if (!hash_equals($expectSha, hash('sha256', $bytes))) {
            schauboard_update_rrmdir($work);
            return ['ok' => false, 'error' => t('update.error.checksum', 'Prüfsumme stimmt nicht (Download beschädigt/manipuliert) – abgebrochen.')];
        }
    }

    $zipPath = $work . '/update.zip';
    if (@file_put_contents($zipPath, $bytes) === false) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.zip_save', 'ZIP konnte nicht gespeichert werden.')];
    }

    // 3) Entpacken
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.zip_open', 'ZIP konnte nicht geöffnet werden.')];
    }
    $extractDir = $work . '/extracted';
    @mkdir($extractDir, 0775, true);
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.zip_extract', 'ZIP konnte nicht entpackt werden.')];
    }
    $zip->close();

    // 4) Plausibilitaet: erwartete Struktur + echt neuere Version
    $srcRoot = $extractDir . '/schauboard-v3';
    if (!is_file($srcRoot . '/version.php') || !is_dir($srcRoot . '/core') || !is_file($srcRoot . '/index.php')) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.structure', 'Paketstruktur unerwartet – abgebrochen.')];
    }
    $newMeta = @include $srcRoot . '/version.php';
    $newVer = is_array($newMeta) ? (string) ($newMeta['current'] ?? '') : '';
    if ($newVer === '' || version_compare($newVer, $current, '<=')) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.not_newer', 'Paket-Version ({new}) ist nicht neuer als die installierte ({current}).', ['new' => $newVer, 'current' => $current])];
    }

    // 5) Dateien installieren (Backup + Auto-Rollback)
    $backupRoot = $dataDir . '/update_backup';
    $err = null;
    if (!schauboard_update_install_files($srcRoot, schauboard_app_root(), $backupRoot, $err)) {
        schauboard_update_rrmdir($work);
        return ['ok' => false, 'error' => t('update.error.install_failed', 'Installation fehlgeschlagen (zurückgerollt): {reason}', ['reason' => ($err ?? t('update.error.unknown', 'unbekannt'))])];
    }

    // 6) Aufraeumen + Opcache leeren, damit der neue Code sofort greift
    schauboard_update_rrmdir($work);
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return ['ok' => true, 'version' => $newVer, 'previous' => $current];
}
