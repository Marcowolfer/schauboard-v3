<?php

/**
 * Schreibt eine Datei atomar: erst in eine .tmp-Datei, dann per rename().
 * Ein abgebrochener Request oder eine volle Platte hinterlaesst damit nie
 * eine halb geschriebene (= kaputte) Datei.
 */
function schauboard_write_file(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Rotierende Backups: <datei>.bak.1 (neuestes) bis .bak.$keep.
 */
function schauboard_rotate_backup(string $path, int $keep = 5): void
{
    if (!is_file($path)) {
        return;
    }

    for ($i = $keep - 1; $i >= 1; $i--) {
        $from = "$path.bak.$i";
        if (is_file($from)) {
            @rename($from, "$path.bak." . ($i + 1));
        }
    }

    @copy($path, "$path.bak.1");
}

function schauboard_read_json_file(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = (string) file_get_contents($path);
    // BOM tolerieren: manche Editoren/Transfers schreiben ein UTF-8-BOM,
    // an dem json_decode sonst scheitern wuerde.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function schauboard_write_json_file(string $path, array $payload, bool $backup = false): bool
{
    if ($backup) {
        schauboard_rotate_backup($path);
    }

    return schauboard_write_file(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function schauboard_settings_defaults(): array
{
    return [
        'system' => [
            'timezone' => 'Europe/Zurich',
            'language' => 'de',
            'default_slide_duration' => 10,
            'default_transition' => 'fade',
            'offline_timeout_minutes' => 5,
        ],
        'weather' => [
            'enabled' => true,
            'location' => 'Zurich,CH',
            'provider' => 'wttr.in',
        ],
        'maintenance' => [
            'enabled' => false,
            'message' => '',
        ],
        'branding' => [
            'name' => 'Schauboard',
        ],
    ];
}

function schauboard_slides_defaults(): array
{
    return [
        [
            'id' => 'slide_welcome',
            'name' => 'Willkommen',
            'bg_color' => '#1a1a2e',
            'bg_image' => '',
            'duration' => 10,
            'blocks' => [],
        ],
    ];
}

function schauboard_playlists_defaults(): array
{
    return [
        [
            'id' => 'playlist_default',
            'name' => 'Standard',
            'slide_ids' => ['slide_welcome'],
        ],
    ];
}

function schauboard_displays_defaults(): array
{
    return [
        [
            'id' => 'default',
            'name' => 'Standard Display',
            'default_playlist_id' => 'playlist_default',
            'last_seen_at' => null,
            'token' => '',
        ],
    ];
}

function schauboard_schedules_defaults(): array
{
    return [];
}

function schauboard_rules_defaults(): array
{
    return [];
}

function schauboard_json_path(string $name): string
{
    $config = schauboard_config();
    return match ($name) {
        'settings' => dirname(__DIR__) . '/data/settings.json',
        'slides' => dirname(__DIR__) . '/data/slides.json',
        'playlists' => dirname(__DIR__) . '/data/playlists.json',
        'displays' => dirname(__DIR__) . '/data/displays.json',
        'schedules' => dirname(__DIR__) . '/data/schedules.json',
        'templates' => $config['templates_file'],
        'rules' => dirname(__DIR__) . '/data/rules.json',
        default => dirname(__DIR__) . '/data/' . $name . '.json',
    };
}

function schauboard_read_dataset(string $name): array
{
    return match ($name) {
        'settings' => schauboard_read_json_file(schauboard_json_path('settings'), schauboard_settings_defaults()),
        'slides' => schauboard_read_json_file(schauboard_json_path('slides'), schauboard_slides_defaults()),
        'playlists' => schauboard_read_json_file(schauboard_json_path('playlists'), schauboard_playlists_defaults()),
        'displays' => schauboard_read_json_file(schauboard_json_path('displays'), schauboard_displays_defaults()),
        'schedules' => schauboard_read_json_file(schauboard_json_path('schedules'), schauboard_schedules_defaults()),
        'templates' => schauboard_read_json_file(schauboard_json_path('templates'), []),
        'rules' => schauboard_read_json_file(schauboard_json_path('rules'), schauboard_rules_defaults()),
        default => [],
    };
}

function schauboard_write_dataset(string $name, array $payload): bool
{
    // Inhalts-Datensaetze bekommen rotierende Backups, damit ein versehentliches
    // Leerspeichern oder ein kaputter Save rueckholbar bleibt.
    $backup = in_array($name, ['slides', 'playlists', 'displays', 'schedules', 'templates'], true);
    return schauboard_write_json_file(schauboard_json_path($name), $payload, $backup);
}

function schauboard_ensure_data_files(): void
{
    $dataDir = dirname(schauboard_json_path('settings'));
    $defaultsDir = $dataDir . '/defaults';

    // 1) Beim ERSTEN Start die mitgelieferten Demo-/Default-Inhalte aus
    //    data/defaults/ uebernehmen - aber NUR, wenn die Live-Datei fehlt.
    //    Die Live-Dateien data/*.json liegen NICHT im Release-Paket; dadurch
    //    ueberschreibt ein Update (Dateien drueberkopieren) NIE echte Daten.
    if (is_dir($defaultsDir)) {
        foreach ((glob($defaultsDir . '/*.json') ?: []) as $seed) {
            $target = $dataDir . '/' . basename($seed);
            if (!is_file($target)) {
                @copy($seed, $target);
            }
        }
    }

    // 2) Fallback fuer Pflicht-Datensaetze, falls keine Seed-Datei vorhanden ist.
    $datasets = [
        'settings' => schauboard_settings_defaults(),
        'slides' => schauboard_slides_defaults(),
        'playlists' => schauboard_playlists_defaults(),
        'displays' => schauboard_displays_defaults(),
        'schedules' => schauboard_schedules_defaults(),
        'rules' => schauboard_rules_defaults(),
    ];
    foreach ($datasets as $name => $payload) {
        $path = schauboard_json_path($name);
        if (!is_file($path)) {
            schauboard_write_json_file($path, $payload);
        }
    }

    // 3) Schutzregel fuer das data/-Verzeichnis (Apache): JSON + Backups nicht
    //    oeffentlich ueber /data/ abrufbar.
    $htaccess = $dataDir . '/.htaccess';
    if (is_dir($dataDir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
}

function schauboard_find_by_id(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

/**
 * Signatur ueber die Inhalts-Dateien. Aendert sie sich, weiss das Display,
 * dass es neu laden muss (Live-Reload).
 */
function schauboard_revision(): string
{
    $parts = [];
    foreach (['slides', 'playlists', 'displays', 'schedules', 'settings'] as $name) {
        $path = schauboard_json_path($name);
        $parts[] = is_file($path) ? (string) filemtime($path) : '0';
    }

    return substr(md5(implode('-', $parts)), 0, 12);
}

/**
 * Loest die aktuell aktive Playlist-ID fuer ein Display auf:
 * Standard-Playlist des Displays, ggf. ueberschrieben durch ein aktives
 * Zeitfenster. Behandelt Fenster ueber Mitternacht (from > to).
 * Wird von display/index.php UND api/revision.php genutzt, damit der
 * Live-Reload auch bei reinen Tageszeit-Wechseln (ohne Datei-Aenderung) greift.
 */
function schauboard_active_playlist_id(array $display, array $schedules, DateTime $now): string
{
    $playlistId = (string) ($display['default_playlist_id'] ?? 'playlist_default');
    $displayId = (string) ($display['id'] ?? 'default');

    $dayMap = ['Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed', 'Thu' => 'thu', 'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun'];
    $dayKey = $dayMap[$now->format('D')] ?? 'mon';
    $t = $now->format('H:i');

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
        // Normales Fenster: from<=to. Fenster ueber Mitternacht: from>to (z. B. 22:00-02:00).
        $inWindow = ($from <= $to)
            ? ($t >= $from && $t <= $to)
            : ($t >= $from || $t <= $to);
        if ($inWindow) {
            $playlistId = (string) ($candidate['playlist_id'] ?? $playlistId);
            break;
        }
    }

    return $playlistId;
}

/**
 * Display-spezifische Revision: Datei-Signatur + aktuell aufgeloeste Playlist.
 * Aendert sich auch dann, wenn ein Zeitfenster die Playlist wechselt, ohne
 * dass eine Datei veraendert wurde -> der 5s-Poll erkennt den Wechsel.
 */
function schauboard_display_revision(array $display, array $schedules, DateTime $now): string
{
    return schauboard_revision() . '-' . schauboard_active_playlist_id($display, $schedules, $now);
}
