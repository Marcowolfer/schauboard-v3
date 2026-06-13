# Schauboard v3 – Abnahme-Checkliste (Beta)

Diese Liste durchgehen, um v3 abzunehmen. Lokal starten:

```bash
php -S 127.0.0.1:8080 -t schauboard-v3
```

## Erststart
- [ ] `/admin/` öffnen → Setup-Seite erscheint, Passwort (min. 8 Zeichen) setzen.
- [ ] Nach Login landet man im Editor mit der Demo-Folie „Willkommen".

## Display
- [ ] `/?display=default` zeigt die Demo: Titel, Uhr (tickt), Wetter (lädt echte Temperatur), Laufband (scrollt).
- [ ] Nach ~12 s wechselt es zur 2. Folie (Tabelle + QR + Countdown) mit Übergang.
- [ ] QR-Code ist scanbar, Tabelle lesbar.

## Editor – Module (alle 10)
- [ ] Aus der Werkzeugleiste jeden Block per Klick **oder** Drag-and-Drop auf die Bühne legen:
      Text, Titel, Uhr, Bild, Wetter, Laufband, Tabelle, Webseite, QR-Code, Countdown.
- [ ] Block anklicken → verschieben (Snap-Linien), Ecke ziehen → Grösse ändern.
- [ ] Doppelklick auf Block → Dialog; Inhalt/Farbe/Schrift ändern → „Übernehmen".
- [ ] Pfeiltasten verschieben den markierten Block, Entf löscht ihn.

## Tabelle aus Excel (Anforderung #1)
- [ ] Tabellen-Block → Dialog → in Excel/Sheets Zellen markieren, kopieren,
      ins Feld „Aus Excel einfügen" mit Strg+V einfügen → Raster übernimmt die Daten.

## Webseite (Anforderung #2)
- [ ] Webseiten-Block mit URL (z. B. `https://example.com`) → erscheint auf dem Display als Einbettung.
- [ ] Hinweis: manche Seiten verbieten Einbettung – dann erscheint ein Platzhalter statt Fehler.

## Multi-Display (Anforderung #3)
- [ ] Reiter **Displays** → „+ Display" → Name vergeben → Speichern.
- [ ] URL kopieren / QR scannen → diese URL in einem zweiten Fenster öffnen.
- [ ] Im Admin wird das Display nach kurzer Zeit als **Online** angezeigt.
- [ ] Verschiedenen Displays verschiedene Playlists zuweisen → jedes zeigt seinen Inhalt.

## Playlists & Zeitpläne
- [ ] **Playlists**: Folien per Häkchen zu einer Playlist zusammenstellen → Speichern.
- [ ] **Zeitpläne** (optional): Zeitfenster anlegen (Display + Playlist + Tage + Uhrzeit) → im
      Fenster zeigt das Display automatisch die geplante Playlist.

## Einstellungen
- [ ] Branding-Name ändern → erscheint im Admin-Kopf.
- [ ] Wartungsmodus aktivieren → Display zeigt Wartungshinweis. Wieder deaktivieren.

## Speichern & Live-Reload
- [ ] Im Editor speichern (Button oder Strg+S) → ein offenes Display lädt innerhalb ~5 s neu.
- [ ] „Vorschau" im Editor zeigt die aktuelle Folie exakt wie das TV-Bild.

## Robustheit
- [ ] Mehrfach speichern erzeugt `data/slides.json.bak.1..5` (Backups).
- [ ] Keine PHP-Warnungen im Browser/Netzwerk-Tab bei den `api/*.php`-Antworten (sauberes JSON).

---
Bekannte Beta-Punkte siehe Code-Review im PR / Commit-Text.
