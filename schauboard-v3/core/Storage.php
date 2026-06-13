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
