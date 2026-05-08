# Schauboard v3 Spezifikation

Version: `3.0.0`
Stand: 21. April 2026
Status: Finalisierte Produktspezifikation fuer den Neuaufbau

## 1. Produktdefinition

Schauboard v3 ist ein selbst gehostetes, kostenloses, dateibasiertes Digital-Signage-System.

Es richtet sich an kleine bis mittlere Installationen, die ohne Cloud-Zwang, ohne Datenbank und ohne kompliziertes Setup auskommen sollen.

Typische Einsatzorte:

- Restaurants
- Praxen
- Läden
- Vereine
- Empfangsbereiche
- Werkstätten
- kleine interne Info-Displays

## 2. Unveraenderliche Kern-Philosophie

1. Einfach
   Keine Datenbank, kein schweres Setup, keine Pflicht zu externer Infrastruktur.

2. Selbst gehostet
   Der Nutzer betreibt Schauboard auf eigenem NAS, Mini-PC, Raspberry Pi oder einfachem Webspace.

3. Kostenlos
   Kein Abo, keine SaaS-Abhängigkeit, keine versteckten Plattformkosten.

4. Laufbar auf einfacher Infrastruktur
   Zielplattformen sind bewusst einfache PHP-Hostings und kleine lokale Systeme.

5. Dateibasiert
   Inhalte, Templates, Settings und Metadaten liegen in Dateien, damit Backups und Migrationen leicht bleiben.

## 3. Produktziel von v3

v3 ist kein Patch auf dem alten Beta-Stand, sondern ein sauberer Neuaufbau.

Ziel ist ein System, das:

- deutlich strukturierter als der alte Beta-Stand ist
- moderner und sauberer wirkt
- mehrere Displays sinnvoll unterstützt
- auf einem robusten Datenmodell basiert
- spätere Erweiterungen erlaubt, ohne wieder ein Monolith zu werden

## 4. Produktumfang von v3

## 4.1 Muss in v3 hinein

- Setup beim ersten Start
- Passwort-Hash in Datei
- Login mit Session
- Folienbasierte Anzeige
- Blockbasierter Editor
- Hintergründe pro Slide
- Uploads fuer Medien
- Display-spezifische Wiedergabe
- Playlists
- Globale Einstellungen
- Zeitsteuerung
- Templates
- Wetter, Uhr, Text, Heading, Bild, Ticker
- Backup/Restore
- Basis-Status fuer Displays

## 4.2 Darf spaeter kommen

- erweiterte Regeln
- PDF-Viewer mit perfekter Offline-Faehigkeit
- Video-Quellen erweitert
- Tabellenblock
- Screenshot-Automation
- Master/Slave-Sync
- Importer fuer alte Stände
- Rollen / Rechte

## 5. Schauboard v3 Datenmodell

Die Datenstruktur muss in v3 klar getrennt sein.

Nicht alles in eine Datei mischen.

## 5.1 settings.json

Globale Systemeinstellungen.

Beispiel:

```json
{
  "system": {
    "timezone": "Europe/Zurich",
    "language": "de",
    "default_slide_duration": 10,
    "default_transition": "fade",
    "offline_timeout_minutes": 5
  },
  "weather": {
    "enabled": true,
    "location": "Zurich,CH",
    "provider": "wttr.in"
  },
  "maintenance": {
    "enabled": false,
    "message": ""
  },
  "branding": {
    "name": "Schauboard"
  }
}
```

## 5.2 slides.json

Enthält nur Slides.

Jede Slide ist ein wiederverwendbares Anzeigeobjekt.

Beispielstruktur:

```json
[
  {
    "id": "slide_welcome",
    "name": "Willkommen",
    "bg_color": "#1a1a2e",
    "bg_image": "",
    "duration": 10,
    "blocks": []
  }
]
```

## 5.3 playlists.json

Eine Playlist ist eine geordnete Liste von Slide-IDs.

Beispiel:

```json
[
  {
    "id": "playlist_default",
    "name": "Standard",
    "slide_ids": ["slide_welcome", "slide_weather"]
  }
]
```

## 5.4 displays.json

Definiert alle bekannten Displays.

Beispiel:

```json
[
  {
    "id": "empfang",
    "name": "Empfang",
    "default_playlist_id": "playlist_default",
    "last_seen_at": null,
    "token": ""
  }
]
```

## 5.5 schedules.json

Zeitsteuerung fuer Display und Playlist.

Wichtig:
Kein Cron als Startmodell.
v3 nutzt ein lesbares, UI-freundliches Zeitmodell.

Beispiel:

```json
[
  {
    "id": "morning_reception",
    "name": "Morgens Empfang",
    "display_id": "empfang",
    "playlist_id": "playlist_breakfast",
    "days": ["mon", "tue", "wed", "thu", "fri"],
    "from": "06:00",
    "to": "11:00"
  }
]
```

## 5.6 templates.json

Template speichert einen benannten Slide-Ausgangspunkt.

Beispiel:

```json
[
  {
    "id": "tpl_logo_clock",
    "name": "Logo mit Uhr",
    "slide": {
      "bg_color": "#101820",
      "bg_image": "",
      "duration": 10,
      "blocks": []
    }
  }
]
```

## 5.7 rules.json

Rules werden in v3 bewusst klein gehalten.

Keine generische Enterprise-Rule-Engine.

Unterstützte Regeltypen in v3:

- Display-Regel
- Zeit-Regel
- Wochentag-Regel
- Datumsbereich-Regel
- Wetter-Regel

Beispiel:

```json
[
  {
    "id": "rain_offer",
    "target_type": "slide",
    "target_id": "slide_rain_offer",
    "show_if": {
      "weather_condition": "rain"
    }
  }
]
```

## 6. Multi-Display Konzept

Multi-Display ist ein Kernfeature von v3.

Ein Schauboard-Server kann mehrere Displays versorgen, die unterschiedliche Inhalte erhalten.

Beispiele:

- Eingang
- Theke
- Warteraum
- Terrasse
- Empfang
- Küche

## 6.1 Display-Identifikation

Ein Display wird über URL oder Konfiguration identifiziert.

Beispiel:

- `/?display=empfang`
- `/?display=theke`

Optional spaeter:

- Token-basierte Registrierung
- Device-Mapping

## 6.2 Display-Zustand

Ein Display sendet periodisch Heartbeats.

Online-Status:

- online, wenn Heartbeat innerhalb des konfigurierten Fensters
- offline, wenn kein Heartbeat mehr kommt

Speicherung:

- `last_seen_at` in `displays.json`

## 6.3 Display-Dashboard

Das Admin-Backend zeigt:

- Name des Displays
- Online/Offline
- letzte Meldung
- zugewiesene Standard-Playlist
- aktuelle aktive Playlist

## 7. Admin-Funktionsumfang

## 7.1 Editor

- Slides anlegen
- Slides löschen
- Slides duplizieren
- Blöcke platzieren
- Blöcke bearbeiten
- Reihenfolge / Z-Index
- Sperren
- Hintergrundfarbe
- Hintergrundbild
- Speichern
- Live-Reload

## 7.2 Blocktypen fuer v3.0.0

- Text
- Heading
- Image
- Clock
- Weather
- Ticker

## 7.3 Blocktypen fuer spaeter

- PDF
- Video
- Table

## 7.4 Template-System

- aktuelle Slide als Template speichern
- neue Slide aus Template erstellen
- Templates benennen
- Templates auflisten

spaeter:

- Template umbenennen
- Template löschen
- Favoriten

## 7.5 Settings-Bereich

Eigene Settings-Seite statt chaotisch verteilter Optionen.

Bereiche:

- System
- Anzeige
- Wetter
- Displays
- Backup

## 7.6 Display-Manager

- neue Displays anlegen
- bestehende Displays benennen
- Playlist-Zuweisung
- Online-Status anzeigen

## 7.7 Schedule-Editor

Keine Cron-Syntax fuer den Nutzer.

UI-basiert:

- Display wählen
- Playlist wählen
- Tage wählen
- Startzeit
- Endzeit

## 8. Display-Rendering

Die öffentliche Anzeige muss:

- performant sein
- sauber skalieren
- mit mehreren Displays arbeiten
- Zeitsteuerung beachten
- Heartbeat senden
- offline sinnvoll reagieren

## 8.1 Rendering-Regeln

- Anzeige bestimmt aktives Display
- Anzeige lädt globale Settings
- Anzeige ermittelt aktive Playlist
- Anzeige ermittelt daraus aktive Slides
- Anzeige rendert Slideshow

## 8.2 Offline/Fallback

Wenn keine passende Playlist aktiv ist:

- Fallback-Playlist anzeigen
oder
- Maintenance-/Leerzustand anzeigen

## 8.3 Frontend-Qualität

Die Anzeige soll nicht wie rohe HTML-Ausgabe wirken.

Ziele:

- hochwertige Grundoptik
- klare Typografie
- stabile Animationen
- professioneller Leerzustand

## 9. Auth und Setup

Pflicht fuer v3:

- Setup beim ersten Start
- Passwort wird als Hash-Datei gespeichert
- Login mit Session
- Reset durch Löschen der Passwortdatei

spaeter:

- Passwort ändern im Admin
- Session-Timeout konfigurierbar

## 10. Backup und Restore

v3 soll ein einfaches Backup-Konzept haben.

## 10.1 Backup

Ein Backup enthält:

- alle JSON-Dateien aus `data/`
- optionale Medien aus `uploads/`
- Metadaten

Format:

- ZIP-Datei

## 10.2 Restore

- Backup auswählen
- validieren
- zurückspielen

## 10.3 Export / Import

Zusätzlich sinnvoll:

- Demo-Daten importieren
- Projekt auf andere Instanz übertragen

## 11. Technische Architektur

## 11.1 Stack

- Backend: PHP 8.2+
- Storage: JSON-Dateien
- Frontend Admin: leichtgewichtig, modular
- Frontend Display: bewusst simpel und robust

## 11.2 Kein schweres Framework

Ziel ist:

- einfache Installation
- einfache Deploybarkeit
- geringe Abhängigkeiten

Deshalb:

- kein großes Fullstack-Framework
- lieber saubere modulare Eigenstruktur

## 11.3 Verzeichnisstruktur

```text
schauboard-v3/
  index.php
  version.php
  config.local.php.example
  core/
    bootstrap.php
    Config.php
    Version.php
    Storage.php
    Auth.php
    Sanitizer.php
    Slides/
    Templates/
  admin/
    index.php
    assets/
    views/
  display/
    index.php
    assets/
    views/
  api/
    status.php
    content.php
    displays.php
    playlists.php
    settings.php
    schedules.php
    upload.php
    weather.php
  data/
    settings.json
    slides.json
    playlists.json
    displays.json
    schedules.json
    templates.json
    rules.json
    admin_password.php
    cache/
      weather/
  uploads/
  docs/
```

## 12. Release-Plan

## 12.1 v3.0.0

Basis-Neuaufbau mit:

- Setup/Login
- Settings
- Slides
- Playlists
- Displays
- einfache Schedules
- Text, Heading, Image, Clock, Weather, Ticker
- Templates Basis
- Heartbeat Basis

## 12.2 v3.0.1

Polishing und Robustheit:

- UI-Feinschliff
- Backup/Restore
- Health-Checks
- Import/Export Basis

## 12.3 v3.1.0

Erweiterte Produktstufe:

- Conditional Content
- PDF/Video/Table
- Screenshots optional
- Display-Diagnose

## 13. Nicht-Ziele

Diese Dinge sind bewusst nicht Teil des ersten v3:

- SaaS-Plattform
- Mandantenverwaltung
- komplexes Benutzer- und Rollensystem
- Datenbankpflicht
- generische Enterprise-Regelmaschine
- komplizierte Cron-Konfiguration im UI

## 14. Entscheidung

Schauboard v3 wird als kompletter Neuaufbau entwickelt.

Der bisherige Beta-Stand wird nicht weitergeführt.

Live bleibt `v2.0.1`, bis das neue `v3` produktionsreif ist.

## 15. Nächste konkrete Schritte

1. Setup/Login im neuen Projekt bauen
2. `settings.json`, `slides.json`, `playlists.json`, `displays.json`, `schedules.json` definieren
3. leeres Admin-Layout mit echter Navigation
4. Display-Stage mit `?display=`-Logik aufsetzen
5. Save/Load-Endpunkte implementieren
