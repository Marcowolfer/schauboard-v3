/*
 * Schauboard – gemeinsame Block-Render-Engine.
 *
 * EINE Quelle der Wahrheit fuer Display und Admin-Editor. Jeder Blocktyp wird
 * hier genau einmal gerendert; Display (display.js) und Editor (admin) rufen
 * dieselben Funktionen auf, damit Vorschau und TV identisch aussehen.
 *
 * Koordinatensystem: 1920x1080. Bloecke speichern x/y/w/h in diesen Einheiten
 * und werden beim Rendern in Prozent der Buehne umgerechnet.
 */
window.SchauboardBlocks = (function () {
  'use strict';

  var STAGE_W = 1920;
  var STAGE_H = 1080;

  // Metadaten je Blocktyp: Label, Icon, Standardgroesse, Standardfelder.
  var TYPES = {
    text:      {label: 'Text',     icon: '📝', w: 460, h: 160},
    heading:   {label: 'Titel',    icon: '🔠', w: 900, h: 180},
    clock:     {label: 'Uhr',      icon: '🕒', w: 360, h: 150},
    image:     {label: 'Bild',     icon: '🖼️', w: 520, h: 360},
    weather:   {label: 'Wetter',   icon: '⛅', w: 460, h: 360},
    ticker:    {label: 'Laufband', icon: '📰', w: 1920, h: 110},
    table:     {label: 'Tabelle',  icon: '▦',  w: 760, h: 420},
    webpage:   {label: 'Webseite', icon: '🌐', w: 900, h: 600},
    qrcode:    {label: 'QR-Code',  icon: '🔳', w: 320, h: 380},
    countdown: {label: 'Countdown', icon: '⏳', w: 560, h: 240}
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function num(value, fallback) {
    var n = Number(value);
    return isFinite(n) ? n : fallback;
  }

  // Standardfelder fuer einen neuen Block eines Typs.
  function defaults(type) {
    var meta = TYPES[type] || TYPES.text;
    var base = {
      type: type,
      w: meta.w,
      h: meta.h,
      base_w: meta.w,
      base_h: meta.h,
      color: '#ffffff',
      align: type === 'clock' || type === 'countdown' || type === 'qrcode' ? 'center' : 'left',
      font_size: type === 'heading' ? 96 : type === 'clock' ? 96 : type === 'countdown' ? 80 : 42
    };
    if (type === 'text') { base.text = 'Neuer Text'; base.bold = false; }
    if (type === 'heading') { base.text = 'Überschrift'; base.bold = true; }
    if (type === 'clock') { base.clock_format = 'HH:MM'; base.show_date = false; }
    if (type === 'image') { base.src = ''; base.fit = 'cover'; }
    if (type === 'weather') { base.city = ''; base.font_size = 40; } // leer = globalen Standardort aus den Einstellungen nutzen
    if (type === 'ticker') { base.text = 'Willkommen bei Schauboard – hier läuft Ihr Lauftext.'; base.speed = 60; base.bg = '#313244'; base.font_size = 48; }
    if (type === 'table') {
      base.table_data = [['Produkt', 'Preis'], ['Kaffee', 'CHF 4.50'], ['Tee', 'CHF 3.80']];
      base.header_bg = '#313244'; base.header_color = '#cba6f7';
      base.cell_color = '#ffffff'; base.border_color = '#45475a';
      base.font_size = 30;
    }
    if (type === 'webpage') { base.url = ''; base.refresh_minutes = 0; base.zoom = 100; }
    if (type === 'qrcode') { base.data = 'https://schauboard.ch'; base.label = ''; base.font_size = 30; }
    if (type === 'countdown') { base.target = ''; base.label = 'Countdown'; }
    return base;
  }

  // Skalierungsfaktor fuer Text/Heading, damit der Text mit der Blockbreite waechst.
  function blockScale(block) {
    var type = block.type || 'text';
    if (type !== 'text' && type !== 'heading') return 1;
    var baseW = Math.max(40, num(block.base_w, num(block.w, 460)));
    var baseH = Math.max(24, num(block.base_h, num(block.h, 160)));
    var w = Math.max(40, num(block.w, baseW));
    var h = Math.max(24, num(block.h, baseH));
    return Math.max(0.3, Math.min(w / baseW, h / baseH));
  }

  function autoFontSize(block, baseFont, refW, refH) {
    var w = Math.max(40, num(block.w, refW));
    var h = Math.max(24, num(block.h, refH));
    var scaled = Math.round(baseFont * Math.min(w / refW, h / refH));
    return Math.max(12, Math.min(scaled, Math.round(h * 0.74)));
  }

  function applyPosition(node, block) {
    node.style.left = (num(block.x, 0) / STAGE_W * 100) + '%';
    node.style.top = (num(block.y, 0) / STAGE_H * 100) + '%';
    node.style.width = (num(block.w, 0) / STAGE_W * 100) + '%';
    node.style.height = (num(block.h, 0) / STAGE_H * 100) + '%';
  }

  // QR-Code Bild-URL (externer Generator – siehe Hinweis im Editor).
  function qrSrc(data, size) {
    var s = Math.max(120, Math.min(800, Math.round(size || 320)));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' + s + 'x' + s +
      '&margin=8&data=' + encodeURIComponent(data || ' ');
  }

  // Baut den Innen-Inhalt eines Blocks (ohne Positionierung). mode: 'display' | 'editor'.
  // opts.scale: Buehnen-Skalierung (z. B. Editor-Canvas 836px / 1920 = 0.435).
  // Positionen sind in % -> brauchen das nicht; Schriftgroessen sind in px aus
  // dem 1920-Koordinatensystem -> muessen mit der Buehnenbreite mitskaliert werden.
  function renderInner(block, mode, opts) {
    var type = block.type || 'text';
    var sc = (opts && opts.scale) ? opts.scale : 1;
    var inner = document.createElement('div');
    inner.className = 'sb-block-inner';
    inner.style.color = block.color || '#ffffff';
    inner.style.textAlign = block.align || 'left';

    if (type === 'text' || type === 'heading') {
      var scale = blockScale(block);
      inner.style.width = (100 / scale) + '%';
      inner.style.height = (100 / scale) + '%';
      inner.style.transform = 'scale(' + scale + ')';
      inner.style.fontSize = (Math.max(10, num(block.font_size, 42)) * sc) + 'px';
      inner.style.fontWeight = block.bold || type === 'heading' ? '800' : '700';
      inner.innerHTML = escapeHtml(block.text || (type === 'heading' ? 'Überschrift' : 'Textblock')).replace(/\n/g, '<br>');
      return inner;
    }

    if (type === 'clock') {
      inner.style.fontSize = (autoFontSize(block, num(block.font_size, 96), 360, 150) * sc) + 'px';
      inner.dataset.clock = '1';
      inner.dataset.clockFormat = block.clock_format || 'HH:MM';
      inner.dataset.showDate = block.show_date ? '1' : '';
      inner.innerHTML = '<span class="sb-clock-time">--:--</span>' + (block.show_date ? '<span class="sb-clock-date"></span>' : '');
      return inner;
    }

    if (type === 'image') {
      inner.style.padding = '0';
      if (block.src) {
        var img = document.createElement('img');
        img.src = block.src;
        img.alt = '';
        img.draggable = false; // sonst startet im Editor der native Bild-Drag und der Block laesst sich nicht verschieben
        img.style.objectFit = block.fit || 'cover';
        inner.appendChild(img);
      } else {
        inner.innerHTML = '<div class="sb-block-empty">Bild – Quelle im Editor setzen</div>';
      }
      return inner;
    }

    if (type === 'weather') {
      var wFont = autoFontSize(block, num(block.font_size, 40), 460, 360) * sc;
      // Eigene Stadt am Block hat Vorrang, sonst globaler Standardort aus den Einstellungen.
      var wCity = (block.city && String(block.city).trim()) || (opts && opts.defaultCity) || 'Zurich';
      inner.dataset.weather = '1';
      inner.dataset.city = wCity;
      inner.innerHTML =
        '<div class="sb-w-emoji" style="font-size:' + (wFont * 2.2) + 'px">⛅</div>' +
        '<div class="sb-w-city" style="font-size:' + wFont + 'px">' + escapeHtml(wCity) + '</div>' +
        '<div class="sb-w-temp" style="font-size:' + (wFont * 1.6) + 'px">-- °C</div>' +
        '<div class="sb-w-desc" style="font-size:' + (wFont * 0.8) + 'px">Lädt…</div>';
      return inner;
    }

    if (type === 'ticker') {
      var duration = Math.max(4, Math.round(2400 / Math.max(10, num(block.speed, 60))));
      var track = document.createElement('div');
      track.className = 'sb-ticker-track';
      track.dataset.speed = String(num(block.speed, 60));
      track.style.fontSize = (Math.max(12, num(block.font_size, 48)) * sc) + 'px';
      track.style.animationDuration = duration + 's'; // Startwert; applyLive korrigiert nach Layout
      track.style.padding = '0 ' + (60 * sc) + 'px';
      track.textContent = block.text || 'Laufband';
      inner.appendChild(track);
      return inner;
    }

    if (type === 'table') {
      var rows = Array.isArray(block.table_data) ? block.table_data : [];
      var fs = Math.max(10, num(block.font_size, 30)) * sc;
      var html = '<table style="font-size:' + fs + 'px;">';
      rows.forEach(function (row, ri) {
        html += '<tr>';
        (row || []).forEach(function (cell) {
          if (ri === 0) {
            html += '<th style="background:' + escapeHtml(block.header_bg || '#313244') + ';color:' + escapeHtml(block.header_color || '#cba6f7') + ';border-bottom:2px solid ' + escapeHtml(block.border_color || '#45475a') + '">' + escapeHtml(cell) + '</th>';
          } else {
            html += '<td style="color:' + escapeHtml(block.cell_color || '#ffffff') + ';border-bottom:1px solid ' + escapeHtml(block.border_color || '#45475a') + '">' + escapeHtml(cell) + '</td>';
          }
        });
        html += '</tr>';
      });
      html += '</table>';
      inner.style.color = '';
      inner.innerHTML = html;
      return inner;
    }

    if (type === 'webpage') {
      inner.style.padding = '0';
      if (block.url && mode === 'display') {
        var zoom = Math.max(25, Math.min(200, num(block.zoom, 100))) / 100;
        var frame = document.createElement('iframe');
        frame.src = block.url;
        frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.loading = 'lazy';
        if (zoom !== 1) {
          frame.style.width = (100 / zoom) + '%';
          frame.style.height = (100 / zoom) + '%';
          frame.style.transform = 'scale(' + zoom + ')';
          frame.style.transformOrigin = 'top left';
        }
        if (block.refresh_minutes > 0) {
          frame.dataset.refresh = String(block.refresh_minutes);
          frame.dataset.baseSrc = block.url;
        }
        inner.appendChild(frame);
      } else {
        inner.innerHTML = '<div class="sb-webpage-fallback">🌐 <strong>Webseite</strong>' +
          '<span class="sb-wp-url">' + escapeHtml(block.url || 'Noch keine URL gesetzt') + '</span>' +
          '<span style="font-size:.72em;opacity:.7">' + (mode === 'editor' ? 'Live-Vorschau erst auf dem Display' : 'Diese Seite erlaubt keine Einbettung') + '</span></div>';
      }
      return inner;
    }

    if (type === 'qrcode') {
      var size = Math.min(num(block.w, 320), num(block.h, 320)) - 40;
      var img = document.createElement('img');
      img.src = qrSrc(block.data, size);
      img.alt = 'QR-Code';
      img.draggable = false; // wie beim Bild-Block: nativen Drag verhindern, sonst klemmt das Verschieben
      // QR wird extern erzeugt (api.qrserver.com) -> im Offline-LAN sichtbarer Hinweis statt leerem Kasten.
      img.onerror = function () { inner.innerHTML = '<div class="sb-block-empty">QR-Code offline nicht verfügbar</div>'; };
      inner.appendChild(img);
      if (block.label) {
        var label = document.createElement('div');
        label.className = 'sb-qr-label';
        label.style.fontSize = (Math.max(12, num(block.font_size, 30)) * sc) + 'px';
        label.textContent = block.label;
        inner.appendChild(label);
      }
      return inner;
    }

    if (type === 'countdown') {
      var cdFont = autoFontSize(block, num(block.font_size, 80), 560, 240) * sc;
      inner.dataset.countdown = '1';
      inner.dataset.target = block.target || '';
      var value = document.createElement('div');
      value.className = 'sb-cd-value';
      value.style.fontSize = cdFont + 'px';
      value.textContent = '--:--:--';
      inner.appendChild(value);
      if (block.label) {
        var cdLabel = document.createElement('div');
        cdLabel.className = 'sb-cd-label';
        cdLabel.style.fontSize = (cdFont * 0.34) + 'px';
        cdLabel.textContent = block.label;
        inner.appendChild(cdLabel);
      }
      return inner;
    }

    inner.innerHTML = '<div class="sb-block-empty">Unbekannter Block</div>';
    return inner;
  }

  // Komplett positionierter Block (Wrapper + Inhalt).
  function render(block, mode, opts) {
    var node = document.createElement('div');
    node.className = 'sb-block ' + (block.type || 'text');
    node.dataset.blockId = block.id || '';
    if (block.type === 'ticker' && block.bg) node.style.background = block.bg;
    applyPosition(node, block);
    node.appendChild(renderInner(block, mode, opts));
    return node;
  }

  /* ===== Live-Verhalten (Uhr, Wetter, Ticker via CSS, Countdown, Webseiten-Refresh) ===== */

  var weatherCache = new Map(); // city -> {at, data}
  var weatherPending = new Set(); // staedte mit laufendem Fetch (verhindert Mehrfach-Requests beim Editor-Drag)
  var WEATHER_TTL = 9 * 60 * 1000;
  var globalLiveStarted = false;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function tickClocks() {
    var now = new Date();
    document.querySelectorAll('[data-clock]').forEach(function (el) {
      var fmt = el.dataset.clockFormat || 'HH:MM';
      var t = fmt === 'HH:MM:SS'
        ? pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds())
        : pad(now.getHours()) + ':' + pad(now.getMinutes());
      var timeEl = el.querySelector('.sb-clock-time');
      if (timeEl) timeEl.textContent = t;
      if (el.dataset.showDate) {
        var dateEl = el.querySelector('.sb-clock-date');
        if (dateEl) dateEl.textContent = now.toLocaleDateString('de-CH', {weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric'});
      }
    });
  }

  function tickCountdowns() {
    var now = Date.now();
    document.querySelectorAll('[data-countdown]').forEach(function (el) {
      var target = el.dataset.target;
      var valueEl = el.querySelector('.sb-cd-value');
      if (!valueEl) return;
      if (!target) { valueEl.textContent = '--:--:--'; return; }
      var diff = new Date(target).getTime() - now;
      if (!isFinite(diff)) { valueEl.textContent = '--:--:--'; return; }
      if (diff <= 0) { valueEl.textContent = '00:00:00'; return; }
      var totalSec = Math.floor(diff / 1000);
      var days = Math.floor(totalSec / 86400);
      var hours = Math.floor((totalSec % 86400) / 3600);
      var mins = Math.floor((totalSec % 3600) / 60);
      var secs = totalSec % 60;
      valueEl.textContent = days > 0
        ? days + 'T ' + pad(hours) + ':' + pad(mins) + ':' + pad(secs)
        : pad(hours) + ':' + pad(mins) + ':' + pad(secs);
    });
  }

  function startGlobalLive() {
    if (globalLiveStarted) return;
    globalLiveStarted = true;
    tickClocks();
    tickCountdowns();
    setInterval(function () { tickClocks(); tickCountdowns(); }, 1000);
  }

  function paintWeather(el, data) {
    var emoji = el.querySelector('.sb-w-emoji');
    var temp = el.querySelector('.sb-w-temp');
    var desc = el.querySelector('.sb-w-desc');
    if (!data || data.error) {
      // Sichtbarer Offline-Zustand statt dauerhaftem "Laedt…" (typisch im reinen LAN ohne Internet).
      if (emoji) emoji.textContent = '🌐';
      if (temp) temp.textContent = '–';
      if (desc) desc.textContent = 'Wetter offline';
      return;
    }
    if (emoji && data.emoji) emoji.textContent = data.emoji;
    if (temp) temp.textContent = data.temp_c + ' °C';
    if (desc) desc.textContent = data.desc || '';
  }

  function loadWeather(root, endpoint) {
    if (!endpoint) return;
    root.querySelectorAll('[data-weather]').forEach(function (el) {
      var city = el.dataset.city || 'Zurich';
      var cached = weatherCache.get(city);
      if (cached) paintWeather(el, cached.data);
      // Gueltige Daten 9 Min cachen; Fehler nur 1 Min, damit es nach Netz-Rueckkehr schnell heilt.
      if (cached && !cached.err && (Date.now() - cached.at) < WEATHER_TTL) return;
      if (cached && cached.err && (Date.now() - cached.at) < 60000) return;
      if (weatherPending.has(city)) return; // bereits ein Request unterwegs
      weatherPending.add(city);
      fetch(endpoint + '?city=' + encodeURIComponent(city))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          weatherCache.set(city, {at: Date.now(), data: data, err: !data || !!data.error});
          document.querySelectorAll('[data-weather]').forEach(function (node) {
            if ((node.dataset.city || 'Zurich') === city) paintWeather(node, data);
          });
        })
        .catch(function () {
          weatherCache.set(city, {at: Date.now(), data: {error: true}, err: true});
          document.querySelectorAll('[data-weather]').forEach(function (node) {
            if ((node.dataset.city || 'Zurich') === city) paintWeather(node, {error: true});
          });
        })
        .catch(function () {})
        .finally(function () { weatherPending.delete(city); });
    });
  }

  var webpageTimers = [];
  function setupWebpageRefresh(root) {
    webpageTimers.forEach(clearInterval);
    webpageTimers = [];
    root.querySelectorAll('iframe[data-refresh]').forEach(function (frame) {
      var minutes = Number(frame.dataset.refresh);
      if (!minutes) return;
      webpageTimers.push(setInterval(function () {
        frame.src = frame.dataset.baseSrc;
      }, minutes * 60 * 1000));
    });
  }

  // Ticker-Dauer nach dem Layout aus der echten Track-Breite berechnen, damit die
  // Lesegeschwindigkeit konstant bleibt (unabhaengig von Textlaenge/Aufloesung).
  function setupTickers(root) {
    root.querySelectorAll('.sb-ticker-track').forEach(function (track) {
      var speed = Number(track.dataset.speed) || 60;
      var pxPerSec = Math.max(20, speed * 2); // speed 60 -> 120 px/s
      var trackW = track.scrollWidth || track.offsetWidth || 600;
      var dur = Math.max(4, (trackW * 2) / pxPerSec); // Keyframe laeuft 100% -> -100% = 2x Trackbreite
      track.style.animationDuration = dur.toFixed(1) + 's';
    });
  }

  // Startet/aktualisiert alle Live-Elemente unter root.
  function applyLive(root, opts) {
    opts = opts || {};
    startGlobalLive();
    if (opts.weatherEndpoint) loadWeather(root, opts.weatherEndpoint);
    setupWebpageRefresh(root);
    setupTickers(root);
  }

  return {
    STAGE_W: STAGE_W,
    STAGE_H: STAGE_H,
    TYPES: TYPES,
    defaults: defaults,
    render: render,
    renderInner: renderInner,
    applyPosition: applyPosition,
    applyLive: applyLive,
    blockScale: blockScale,
    escapeHtml: escapeHtml,
    qrSrc: qrSrc
  };
})();
