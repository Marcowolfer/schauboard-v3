<?php

function schauboard_write_file(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return file_put_contents($path, $contents, LOCK_EX) !== false;
}

function schauboard_read_json_file(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function schauboard_write_json_file(string $path, array $payload): bool
{
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
    return schauboard_write_json_file(schauboard_json_path($name), $payload);
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
