<?php

function schauboard_sanitize_text($value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim(strip_tags((string) $value));
}

function schauboard_sanitize_id($value, string $fallback = ''): string
{
    $value = strtolower(schauboard_sanitize_text($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    $value = trim((string) $value, '-_');

    return $value !== '' ? $value : $fallback;
}

function schauboard_sanitize_bool($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
}

function schauboard_sanitize_time_string($value, string $fallback = '00:00'): string
{
    $value = schauboard_sanitize_text($value);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
}

function schauboard_sanitize_days($value): array
{
    $allowed = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    if (!is_array($value)) {
        return [];
    }

    $days = [];
    foreach ($value as $day) {
        $day = schauboard_sanitize_id($day);
        if (in_array($day, $allowed, true)) {
            $days[] = $day;
        }
    }

    return array_values(array_unique($days));
}

function schauboard_sanitize_color($value, string $fallback = '#1a1a2e'): string
{
    $value = schauboard_sanitize_text($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function schauboard_sanitize_urlish($value): string
{
    $value = schauboard_sanitize_text($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $value)) {
        return $value;
    }

    if (preg_match('#^data:image/[a-z0-9.+-]+;base64,[a-zA-Z0-9/+=]+$#i', $value)) {
        return $value;
    }

    if (preg_match('#^[a-zA-Z0-9/_\-.]+$#', $value)) {
        return $value;
    }

    return '';
}

/**
 * Echte http(s)-URL fuer Webseiten-Bloecke und QR-Codes (kein lokaler Pfad).
 */
function schauboard_sanitize_http_url($value): string
{
    $value = schauboard_sanitize_text($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    return '';
}

/**
 * 2D-Array fuer Tabellen-Bloecke (Zeilen x Zellen), alles als bereinigter Text.
 * Begrenzt auf sinnvolle Groessen, damit ein kaputter Paste nicht das JSON sprengt.
 */
function schauboard_sanitize_table($value): array
{
    if (!is_array($value)) {
        return [['Spalte 1', 'Spalte 2'], ['Wert', 'Wert']];
    }

    $rows = [];
    foreach (array_slice($value, 0, 100) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cells = [];
        foreach (array_slice($row, 0, 20) as $cell) {
            $cells[] = schauboard_sanitize_text($cell);
        }
        if ($cells !== []) {
            $rows[] = $cells;
        }
    }

    return $rows !== [] ? $rows : [['Spalte 1', 'Spalte 2'], ['Wert', 'Wert']];
}

function schauboard_block_types(): array
{
    return ['text', 'heading', 'clock', 'image', 'weather', 'ticker', 'table', 'webpage', 'qrcode', 'countdown', 'animation'];
}

function schauboard_sanitize_block(array $block): array
{
    $type = schauboard_sanitize_id($block['type'] ?? '', 'text');
    if (!in_array($type, schauboard_block_types(), true)) {
        $type = 'text';
    }

    $clean = [
        'id' => schauboard_sanitize_id($block['id'] ?? '', 'block_' . uniqid()),
        'type' => $type,
        'x' => max(0, min(1900, (int) ($block['x'] ?? 120))),
        'y' => max(0, min(1060, (int) ($block['y'] ?? 120))),
        'w' => max(40, min(1920, (int) ($block['w'] ?? 420))),
        'h' => max(40, min(1080, (int) ($block['h'] ?? 140))),
        'base_w' => max(40, min(1920, (int) ($block['base_w'] ?? ($block['w'] ?? 420)))),
        'base_h' => max(40, min(1080, (int) ($block['base_h'] ?? ($block['h'] ?? 140)))),
        'color' => schauboard_sanitize_color($block['color'] ?? '#ffffff', '#ffffff'),
        'align' => in_array(($block['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $block['align'] : 'left',
        'font_size' => max(10, min(400, (int) ($block['font_size'] ?? 42))),
    ];

    // Typ-spezifische Felder
    switch ($type) {
        case 'text':
        case 'heading':
            $clean['text'] = schauboard_sanitize_text($block['text'] ?? '');
            $clean['bold'] = schauboard_sanitize_bool($block['bold'] ?? ($type === 'heading'));
            break;
        case 'clock':
            $clean['clock_format'] = in_array(($block['clock_format'] ?? 'HH:MM'), ['HH:MM', 'HH:MM:SS'], true) ? $block['clock_format'] : 'HH:MM';
            $clean['show_date'] = schauboard_sanitize_bool($block['show_date'] ?? false);
            break;
        case 'image':
            $clean['src'] = schauboard_sanitize_urlish($block['src'] ?? '');
            $clean['fit'] = in_array(($block['fit'] ?? 'cover'), ['cover', 'contain', 'fill'], true) ? $block['fit'] : 'cover';
            break;
        case 'weather':
            $clean['city'] = schauboard_sanitize_text($block['city'] ?? 'Zurich');
            break;
        case 'ticker':
            $clean['text'] = schauboard_sanitize_text($block['text'] ?? '');
            $clean['speed'] = max(10, min(200, (int) ($block['speed'] ?? 60)));
            $clean['bg'] = schauboard_sanitize_color($block['bg'] ?? '#313244', '#313244');
            break;
        case 'table':
            $clean['table_data'] = schauboard_sanitize_table($block['table_data'] ?? null);
            $clean['header_bg'] = schauboard_sanitize_color($block['header_bg'] ?? '#313244', '#313244');
            $clean['header_color'] = schauboard_sanitize_color($block['header_color'] ?? '#cba6f7', '#cba6f7');
            $clean['cell_color'] = schauboard_sanitize_color($block['cell_color'] ?? '#ffffff', '#ffffff');
            $clean['border_color'] = schauboard_sanitize_color($block['border_color'] ?? '#45475a', '#45475a');
            break;
        case 'webpage':
            $clean['url'] = schauboard_sanitize_http_url($block['url'] ?? '');
            $clean['refresh_minutes'] = max(0, min(1440, (int) ($block['refresh_minutes'] ?? 0)));
            $clean['zoom'] = max(25, min(200, (int) ($block['zoom'] ?? 100)));
            break;
        case 'qrcode':
            $clean['data'] = schauboard_sanitize_text($block['data'] ?? '');
            $clean['label'] = schauboard_sanitize_text($block['label'] ?? '');
            break;
        case 'countdown':
            // ISO-Datumszeit, z. B. 2026-12-31T23:59
            $target = schauboard_sanitize_text($block['target'] ?? '');
            $clean['target'] = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $target) ? $target : '';
            $clean['label'] = schauboard_sanitize_text($block['label'] ?? '');
            break;
        case 'animation':
            // Eigenes HTML/CSS. Bewusst NICHT gestrippt (wird in einer Sandbox ohne
            // same-origin gerendert -> isoliert). Nur Groesse begrenzen, damit ein
            // versehentlich riesiger Inhalt das slides.json nicht sprengt.
            $html = is_string($block['html'] ?? null) ? $block['html'] : '';
            $clean['html'] = function_exists('mb_substr') ? mb_substr($html, 0, 1500000) : substr($html, 0, 1500000);
            break;
    }

    return $clean;
}

function schauboard_sanitize_slide(array $slide): array
{
    $blocks = [];
    if (is_array($slide['blocks'] ?? null)) {
        foreach ($slide['blocks'] as $block) {
            if (is_array($block)) {
                $blocks[] = schauboard_sanitize_block($block);
            }
        }
    }

    return [
        'id' => schauboard_sanitize_id($slide['id'] ?? '', 'slide_' . uniqid()),
        'name' => schauboard_sanitize_text($slide['name'] ?? 'Neue Slide'),
        'bg_color' => schauboard_sanitize_color($slide['bg_color'] ?? '#1a1a2e'),
        'bg_image' => schauboard_sanitize_urlish($slide['bg_image'] ?? ''),
        'duration' => max(3, (int) ($slide['duration'] ?? 10)),
        'blocks' => $blocks,
    ];
}

function schauboard_sanitize_playlist(array $playlist): array
{
    $slideIds = [];
    foreach (($playlist['slide_ids'] ?? []) as $slideId) {
        $id = schauboard_sanitize_id($slideId);
        if ($id !== '') {
            $slideIds[] = $id;
        }
    }

    return [
        'id' => schauboard_sanitize_id($playlist['id'] ?? '', 'playlist_' . uniqid()),
        'name' => schauboard_sanitize_text($playlist['name'] ?? 'Neue Playlist'),
        'slide_ids' => array_values(array_unique($slideIds)),
    ];
}

function schauboard_sanitize_display(array $display): array
{
    return [
        'id' => schauboard_sanitize_id($display['id'] ?? '', 'display_' . uniqid()),
        'name' => schauboard_sanitize_text($display['name'] ?? 'Neues Display'),
        'default_playlist_id' => schauboard_sanitize_id($display['default_playlist_id'] ?? '', 'playlist_default'),
        'last_seen_at' => is_scalar($display['last_seen_at'] ?? null) ? (string) $display['last_seen_at'] : null,
        'token' => schauboard_sanitize_text($display['token'] ?? ''),
    ];
}

function schauboard_sanitize_schedule(array $schedule): array
{
    return [
        'id' => schauboard_sanitize_id($schedule['id'] ?? '', 'schedule_' . uniqid()),
        'name' => schauboard_sanitize_text($schedule['name'] ?? 'Neue Zeitsteuerung'),
        'display_id' => schauboard_sanitize_id($schedule['display_id'] ?? '', 'default'),
        'playlist_id' => schauboard_sanitize_id($schedule['playlist_id'] ?? '', 'playlist_default'),
        'days' => schauboard_sanitize_days($schedule['days'] ?? []),
        'from' => schauboard_sanitize_time_string($schedule['from'] ?? '00:00'),
        'to' => schauboard_sanitize_time_string($schedule['to'] ?? '23:59'),
    ];
}
