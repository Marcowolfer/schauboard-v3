<?php

/*
 * Mehrsprachigkeit (DE/EN) - bewusst so gebaut, dass NICHTS kaputtgehen kann.
 *
 * Grundregel: Der deutsche Text steht weiterhin IM CODE, als Rueckfall direkt
 * am Aufruf. Uebersetzt wird nur "nach oben":
 *
 *     t('editor.save', 'Speichern')
 *
 *   - Sprache = de  -> liefert immer 'Speichern' (kein Nachschlagen noetig)
 *   - Sprache = en  -> liefert lang/en.php['editor.save'], sonst 'Speichern'
 *
 * Damit gilt: fehlt ein Schluessel, fehlt die ganze Sprachdatei oder geht sie
 * bei einem Update verloren, zeigt die App exakt das, was sie vorher zeigte.
 * Es gibt keinen Zustand, in dem Texte leer bleiben oder Schluessel auftauchen.
 *
 * Platzhalter der Form {name} werden aus $vars ersetzt.
 * Das Woerterbuch wird zusaetzlich als JSON in die Seiten injiziert, damit der
 * JavaScript-Teil (Editor, Render-Engine) dieselbe Logik nutzt.
 */

function schauboard_available_languages(): array
{
    return ['de' => 'Deutsch', 'en' => 'English'];
}

/**
 * Aktive Sprache aus den Einstellungen (Rueckfall: Deutsch).
 */
function schauboard_language(?string $override = null): string
{
    static $lang = null;

    if ($override !== null) {
        $lang = isset(schauboard_available_languages()[$override]) ? $override : 'de';
        return $lang;
    }
    if ($lang !== null) {
        return $lang;
    }

    // Defensive: falls settings.json unlesbar ist, bleibt es bei Deutsch.
    try {
        $settings = schauboard_read_dataset('settings');
        $candidate = (string) ($settings['system']['language'] ?? 'de');
    } catch (Throwable $e) {
        $candidate = 'de';
    }
    $lang = isset(schauboard_available_languages()[$candidate]) ? $candidate : 'de';

    return $lang;
}

/**
 * Uebersetzungstabelle einer Sprache. Deutsch braucht keine Datei
 * (die Texte stehen im Code), liefert deshalb immer ein leeres Array.
 */
function schauboard_translations(?string $lang = null): array
{
    static $cache = [];

    $lang = $lang ?? schauboard_language();
    if ($lang === 'de') {
        return [];
    }
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
    $data = is_file($file) ? @require $file : null;
    $cache[$lang] = is_array($data) ? $data : [];

    return $cache[$lang];
}

/**
 * Uebersetzt $key; $fallback ist der deutsche Originaltext und wird immer
 * verwendet, wenn keine Uebersetzung vorliegt.
 */
function t(string $key, string $fallback, array $vars = []): string
{
    $text = schauboard_translations()[$key] ?? null;
    if (!is_string($text) || $text === '') {
        $text = $fallback;
    }

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}

/**
 * Wie t(), aber HTML-escaped - fuer die Ausgabe in Templates.
 */
function te(string $key, string $fallback, array $vars = []): string
{
    return htmlspecialchars(t($key, $fallback, $vars), ENT_QUOTES);
}

/**
 * Woerterbuch fuer den JavaScript-Teil (leer bei Deutsch -> dort greifen
 * ebenfalls die im Code hinterlegten Rueckfalltexte).
 */
function schauboard_translations_for_js(): array
{
    return schauboard_translations();
}
