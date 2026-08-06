# Schauboard

**Kostenloses, selbst gehostetes Digital Signage. Ohne Cloud, ohne Datenbank, ohne Docker.**

Macht aus jedem Bildschirm eine Infotafel — Begrüssung, Speisekarte, Öffnungszeiten, Ankündigungen,
Kennzahlen. Dateien auf NAS, Raspberry Pi oder Webspace mit PHP kopieren, Browser öffnen, fertig.
Die Inhalte verlassen deinen Server nie.

*[English version: README.md](README.md)*

![Der Editor](docs/screenshots/editor.png)

## Warum noch ein Signage-Tool?

Die meisten selbst gehosteten Lösungen brauchen Docker, eine Datenbank oder einen Message Broker —
oft alles drei. Schauboard braucht **PHP und einen Ordner**. Die Inhalte liegen als JSON-Dateien auf
der Platte; ein Backup ist das Kopieren eines Verzeichnisses.

- **Keine Datenbank.** Inhalte als JSON, atomar geschrieben, mit rotierenden Sicherungen.
- **Keine Cloud, kein Konto, keine Telemetrie.** Displays sprechen nur mit deinem eigenen Server.
- **Kein Build-Schritt.** Reines PHP und Vanilla-JavaScript — Datei ändern, Seite neu laden.
- **Läuft dort, wo du schon PHP hast.** Synology Web Station, Raspberry Pi, Webhosting, XAMPP.
- **Ein Editor, eine Render-Engine.** Vorschau und TV nutzen exakt denselben Code — die Vorschau
  kann gar nicht vom Ergebnis abweichen.

## Module

Fünfzehn Blocktypen, frei platzierbar auf einer 1920×1080-Bühne:

| | | |
|---|---|---|
| 📝 Text | 🔠 Titel | 🕒 Uhr |
| 🖼️ Bild | 🎞️ Diashow | 🟦 Form |
| ⛅ Wetter (live, optional 3-Tage-Vorschau) | 📡 RSS-/Atom-Feed | 📰 Laufband |
| ▦ Tabelle (direkt aus Excel einfügen) | 🌐 Webseite | 🔳 QR-Code |
| ⏳ Countdown | ✨ HTML/CSS-Animation (Sandbox) | 🎬 Video (Datei, URL, YouTube) |

## Funktionen

- **Multi-Display** — eine URL pro Bildschirm (`/?display=name`), Playlist je Schirm, Online-Status
  per Heartbeat, QR-Code zum Öffnen auf dem TV-Stick.
- **Playlists** — Folien per Drag & Drop in die gewünschte Reihenfolge bringen.
- **Zeitpläne** — andere Playlist je Tageszeit und Wochentag (auch über Mitternacht), dazu ein
  optionaler **Gültigkeitszeitraum pro Folie** für Ferien und Aktionen.
- **Live-Reload** — Displays fragen eine schlanke Revisions-Signatur ab und aktualisieren sich
  Sekunden nach dem Speichern von selbst. Kein Neustart, kein Anfassen des Fernsehers.
- **Vorlagen, Import/Export, Komplett-Backup** — Folien wiederverwenden, Installation umziehen.
- **1-Klick-Update** — prüft das Manifest und die SHA-256 des Pakets, sichert den alten Stand und
  rollt bei Fehlern automatisch zurück. `data/`, `uploads/` und `config.local.php` bleiben unberührt.
- **Wartungsmodus** — Hinweis auf allen Schirmen, während du umbaust.

![Ein Display im Betrieb](docs/screenshots/display.png)

## Schnellstart

```bash
php -S 127.0.0.1:8080 -t schauboard-v3
```

- **Editor:** <http://127.0.0.1:8080/admin/> — beim ersten Aufruf legst du das Passwort fest.
- **Display:** <http://127.0.0.1:8080/?display=default>

Für den echten Betrieb den Inhalt von `schauboard-v3/` ins Web-Root kopieren. Auf dem Fernseher die
Display-URL im Vollbild-/Kiosk-Modus öffnen.

### Voraussetzungen

- **PHP 8.0 oder neuer** (entwickelt und getestet auf 8.2–8.5)
- `allow_url_fopen=On` für Wetter und RSS (beides serverseitige Proxys mit Cache)
- Schreibrechte für PHP auf `data/` und `uploads/`
- PHP-Erweiterung `zip` für das 1-Klick-Update (sonst gibt es den geführten manuellen Download)

### `data/` nicht öffentlich stellen

Dort liegen Inhalte und der Passwort-Hash. Unter **Apache** wird automatisch eine schützende
`data/.htaccess` (`Require all denied`) angelegt. Unter **nginx** ergänzen:

```nginx
location ^~ /data/ { deny all; return 404; }
```

## Aufbau

```
schauboard-v3/
  index.php           → lädt display/index.php (die öffentliche Anzeige)
  assets/
    blocks.css        gemeinsames Block-Styling
    blocks.js         gemeinsame Render-Engine + Live-Verhalten (Uhr, Wetter, RSS, Laufband, …)
    display.js        Slideshow-Controller (Rotation, Übergänge, Heartbeat, Live-Reload)
  core/
    Config.php        Konfiguration (config.local.php überschreibt alles)
    Storage.php       atomare Writes, rotierende Backups, Revisions-Signatur
    Auth.php          bcrypt-Passwort in einer Datei, Session-Login
    Sanitizer.php     validiert und bereinigt jede Eingabe vor dem Speichern
    Updater.php       Update-Prüfung + In-App-Update mit Verifikation und Rollback
  admin/              der Editor (index.php + editor.js.php)
  display/index.php   löst Display → Playlist → Folien auf, rendert über die gemeinsame Engine
  api/                schlanke JSON-Endpunkte (slides, playlists, displays, schedules, settings,
                      upload, preview, weather, rss, heartbeat, revision, backup, update)
  data/               deine Inhalte als JSON (nicht im Repo)
  uploads/            deine hochgeladenen Medien (nicht im Repo)
```

Der Kern ist `assets/blocks.js`: Jeder Blocktyp wird dort genau einmal gerendert, und sowohl die
Editor-Bühne als auch der Fernseher rufen denselben Code auf.

## Sicherheit

- Das Admin-Passwort liegt als bcrypt-Hash in `data/admin_password.php`, nie im Klartext.
  Vergessen? Datei löschen, dann startet die Ersteinrichtung neu.
- **Passwort direkt nach der Installation setzen.** Bis dahin kann jeder, der `/admin/` erreicht,
  die Instanz übernehmen. Am besten den ersten Start machen, bevor der Server im Netz hängt.
- Der Login erneuert die Session-ID; das Cookie ist HttpOnly und SameSite=Lax.
- Wetter- und RSS-Proxy verweigern Loopback- und Link-Local-Ziele und prüfen bei Weiterleitungen
  jeden Sprung erneut. RSS ist kein offener Proxy: Ohne Admin-Sitzung werden nur Feed-URLs geholt,
  die tatsächlich in einer gespeicherten Folie stehen.
- Animations-Blöcke laufen in einer Sandbox ohne Same-Origin-Zugriff.

## Gut zu wissen

- Displays telefonieren nicht nach Hause. Die einzige ausgehende Verbindung ist die Update-Prüfung
  gegen die Projekt-Website; abschaltbar in `config.local.php`:
  ```php
  <?php return ['update_check_enabled' => false];
  ```
- Der QR-Code-Block nutzt einen externen Generator, braucht also Internet auf dem Display. Alles
  andere läuft offline (Wetter und RSS brauchen naturgemäss eine Verbindung auf dem *Server*).

## Dokumentation

- [docs/TESTING.md](docs/TESTING.md) — Abnahme-Checkliste
- [Handbuch (PDF)](https://schauboard.ch/dl/doku/signage-doku.pdf)
- Projektseite: [schauboard.ch](https://schauboard.ch)

## Lizenz

[Apache License 2.0](../LICENSE) — frei nutzbar, änderbar und weitergebbar, auch kommerziell.
Namensnennung gemäss [NOTICE](../NOTICE).
