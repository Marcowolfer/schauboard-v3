# Schauboard v3

Selbst gehostetes, kostenloses, dateibasiertes Digital-Signage-System (PHP 8.2+, keine Datenbank).

> Stand: **v3.0.0** – stabil/produktiv. Loest die v2-Linie als aktuelle Version ab.

## Schnellstart (lokal)

```bash
php -S 127.0.0.1:8080 -t schauboard-v3
```

- Admin: <http://127.0.0.1:8080/admin/> – beim ersten Aufruf Passwort setzen.
- Display: <http://127.0.0.1:8080/?display=default>

Auf einem NAS / Webspace: den Inhalt von `schauboard-v3/` ins Web-Root legen. PHP 8.2+ mit `allow_url_fopen=On` (für das Wetter).

## Architektur

Eine **gemeinsame Render-Engine** (`assets/blocks.js` + `assets/blocks.css`) rendert jeden
Blocktyp genau einmal – Display **und** Admin-Vorschau nutzen denselben Code, damit Vorschau
und TV nie auseinanderdriften.

```
schauboard-v3/
  index.php              -> laedt display/index.php (oeffentliche Anzeige)
  version.php
  assets/
    blocks.css           gemeinsames Block-Styling
    blocks.js            gemeinsame Render-Engine + Live-Verhalten (Uhr, Wetter, Ticker, Countdown)
    display.js           Slideshow-Controller (Rotation, Transition, Heartbeat, Live-Reload)
  core/
    bootstrap.php        laedt alle Core-Module
    Config.php           Konfiguration (config.local.php ueberschreibt)
    Storage.php          atomare Writes, rotierende Backups, BOM-toleranter Reader, Revision
    Auth.php             Passwort-Hash in Datei, Session-Login
    Sanitizer.php        validiert/bereinigt alle Eingaben (Bloecke, Slides, Playlists, …)
    Version.php
  admin/
    index.php            Backend (Editor, Playlists, Displays, Zeitplaene, Einstellungen)
    editor.js.php        gesamte Admin-Logik (eingebunden ins <script>)
    auth_gate.php        Setup-/Login-Seite
  display/index.php      loest aktives Display -> Playlist -> Folien auf, rendert via Engine
  api/
    slides.php playlists.php displays.php schedules.php settings.php   (Login noetig, POST = speichern)
    upload.php           Bild-Upload (Login noetig)
    preview.php          Editor-Vorschau-Entwurf in Session ablegen (Login noetig)
    weather.php          wttr.in-Proxy, 10 Min. Cache (kein Login)
    heartbeat.php        Display meldet sich online (kein Login)
    revision.php         Signatur fuer Live-Reload (kein Login)
    status.php
  data/                  JSON-Dateien (Inhalte + Laufzeitstatus)
  uploads/               hochgeladene Medien
```

## Blocktypen (Module)

Text · Titel · Uhr · Bild · **Wetter** · **Laufband** · **Tabelle** (mit Excel-Paste) ·
**Webseite** · **QR-Code** · **Countdown**

## Datenmodell (data/)

| Datei | Inhalt |
|-------|--------|
| `settings.json` | globale Einstellungen, Branding, Wartungsmodus |
| `slides.json` | Folien mit Bloecken |
| `playlists.json` | geordnete Listen von Slide-IDs |
| `displays.json` | Bildschirme (Name, Standard-Playlist) |
| `schedules.json` | optionale Zeitfenster (Display + Playlist + Tage + Uhrzeit) |
| `heartbeats.json` | Laufzeit: zuletzt online (generiert, nicht im Repo) |

## Multi-Display

Jeder Bildschirm wird ueber `/?display=<id>` aufgerufen. Im Admin unter **Displays**:
Name vergeben → URL kopieren/QR scannen → Playlist zuweisen. Online-Status kommt per
Heartbeat (Schwelle in den Einstellungen). Ohne jede Einrichtung laeuft ein `default`-Display.

## Sicherheit / Betrieb

- Admin-Passwort als bcrypt-Hash in `data/admin_password.php` (nicht im Repo). Reset = Datei loeschen.
- Schreibzugriff von PHP auf `data/` und `uploads/` noetig.
- `data/` darf nicht oeffentlich abrufbar sein. Fuer **Apache** wird automatisch eine
  `data/.htaccess` (`Require all denied`) angelegt. Fuer **Nginx** diese Regel ergaenzen:
  ```nginx
  location ^~ /data/ { deny all; return 404; }
  ```
- Login regeneriert die Session-ID (gegen Fixation); Session-Cookie ist HttpOnly/SameSite=Lax.

Siehe [docs/TESTING.md](docs/TESTING.md) fuer die Abnahme-Checkliste.
