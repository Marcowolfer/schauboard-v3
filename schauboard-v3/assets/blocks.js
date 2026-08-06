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
/*
 * Uebersetzung im Browser - gleiches Prinzip wie t() in PHP: der deutsche Text
 * steht als Rueckfall direkt am Aufruf, uebersetzt wird nur "nach oben".
 *   sbT('editor.save', 'Speichern')
 * Fehlt das Woerterbuch (window.SB_LANG) oder der Schluessel, bleibt es beim
 * deutschen Text - die Oberflaeche kann dadurch nie leer werden.
 */
window.sbT = function (key, fallback, vars) {
  var dict = window.SB_LANG;
  var text = (dict && typeof dict[key] === 'string' && dict[key] !== '') ? dict[key] : fallback;
  if (vars) {
    Object.keys(vars).forEach(function (name) {
      text = text.split('{' + name + '}').join(String(vars[name]));
    });
  }
  return text;
};

window.SchauboardBlocks = (function () {
  'use strict';

  var t = window.sbT;
  var STAGE_W = 1920;
  var STAGE_H = 1080;

  // Metadaten je Blocktyp: Label, Icon, Standardgroesse, Standardfelder.
  var TYPES = {
    text:      {label: t('block.type.text', 'Text'),         icon: '📝', w: 460, h: 160},
    heading:   {label: t('block.type.heading', 'Titel'),     icon: '🔠', w: 900, h: 180},
    clock:     {label: t('block.type.clock', 'Uhr'),         icon: '🕒', w: 360, h: 150},
    image:     {label: t('block.type.image', 'Bild'),        icon: '🖼️', w: 520, h: 360},
    gallery:   {label: t('block.type.gallery', 'Diashow'),   icon: '🎞️', w: 760, h: 480},
    shape:     {label: t('block.type.shape', 'Form'),        icon: '🟦', w: 600, h: 300},
    weather:   {label: t('block.type.weather', 'Wetter'),    icon: '⛅', w: 460, h: 360},
    rss:       {label: t('block.type.rss', 'RSS-Feed'),      icon: '📡', w: 760, h: 460},
    ticker:    {label: t('block.type.ticker', 'Laufband'),   icon: '📰', w: 1920, h: 110},
    table:     {label: t('block.type.table', 'Tabelle'),     icon: '▦',  w: 760, h: 420},
    webpage:   {label: t('block.type.webpage', 'Webseite'),  icon: '🌐', w: 900, h: 600},
    qrcode:    {label: t('block.type.qrcode', 'QR-Code'),    icon: '🔳', w: 320, h: 380},
    countdown: {label: t('block.type.countdown', 'Countdown'), icon: '⏳', w: 560, h: 240},
    animation: {label: t('block.type.animation', 'Animation'), icon: '✨', w: 900, h: 600},
    video:     {label: t('block.type.video', 'Video'),       icon: '🎬', w: 960, h: 540}
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
    if (type === 'text') { base.text = t('block.text.default', 'Neuer Text'); base.bold = false; }
    if (type === 'heading') { base.text = t('block.heading.default', 'Überschrift'); base.bold = true; }
    if (type === 'clock') { base.clock_format = 'HH:MM'; base.show_date = false; }
    if (type === 'image') { base.src = ''; base.fit = 'cover'; }
    if (type === 'gallery') { base.images = []; base.interval = 6; base.fit = 'cover'; }
    if (type === 'shape') { base.kind = 'rect'; base.color = '#5f8cff'; base.opacity = 100; base.radius = 24; }
    if (type === 'weather') { base.city = ''; base.font_size = 40; base.show_forecast = false; } // leer = globalen Standardort aus den Einstellungen nutzen
    if (type === 'rss') { base.url = ''; base.count = 5; base.show_time = true; base.show_source = false; base.font_size = 36; }
    if (type === 'ticker') { base.text = t('block.ticker.default', 'Willkommen bei Schauboard – hier läuft Ihr Lauftext.'); base.speed = 60; base.bg = '#313244'; base.font_size = 48; }
    if (type === 'table') {
      base.table_data = [
        [t('block.table.default.head1', 'Produkt'), t('block.table.default.head2', 'Preis')],
        [t('block.table.default.row1', 'Kaffee'), 'CHF 4.50'],
        [t('block.table.default.row2', 'Tee'), 'CHF 3.80']
      ];
      base.header_bg = '#313244'; base.header_color = '#cba6f7';
      base.cell_color = '#ffffff'; base.border_color = '#45475a';
      base.font_size = 30;
    }
    if (type === 'webpage') { base.url = ''; base.refresh_minutes = 0; base.zoom = 100; }
    if (type === 'qrcode') { base.data = 'https://schauboard.ch'; base.label = ''; base.font_size = 30; }
    if (type === 'countdown') { base.target = ''; base.label = t('block.countdown.default_label', 'Countdown'); }
    if (type === 'animation') { base.html = ''; }
    if (type === 'video') { base.src = ''; base.fit = 'cover'; }
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

  // Extrahiert die YouTube-Video-ID aus diversen Link-Formen (watch, youtu.be, embed, shorts).
  function youtubeId(url) {
    if (!url) return '';
    var m = String(url).match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
    return m ? m[1] : '';
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
      inner.innerHTML = escapeHtml(block.text || (type === 'heading' ? t('block.heading.default', 'Überschrift') : t('block.text.placeholder', 'Textblock'))).replace(/\n/g, '<br>');
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
        inner.innerHTML = '<div class="sb-block-empty">' + escapeHtml(t('block.image.empty', 'Bild – Quelle im Editor setzen')) + '</div>';
      }
      return inner;
    }

    if (type === 'gallery') {
      inner.style.padding = '0';
      var gImgs = Array.isArray(block.images) ? block.images.filter(Boolean) : [];
      if (!gImgs.length) {
        inner.innerHTML = '<div class="sb-block-empty">' + escapeHtml(t('block.gallery.empty', 'Diashow – Bilder im Editor hinzufügen')) + '</div>';
        return inner;
      }
      var gWrap = document.createElement('div');
      gWrap.className = 'sb-gallery';
      // Rotation nur live (Display/Vorschau); im Editor statisch das erste Bild,
      // sonst wuerde jeder Canvas-Neuaufbau Timer ansammeln.
      if (mode === 'display') {
        gWrap.dataset.gallery = '1';
        gWrap.dataset.interval = String(Math.max(2, num(block.interval, 6)));
      }
      gImgs.forEach(function (src, gi) {
        var gImg = document.createElement('img');
        gImg.src = src;
        gImg.alt = '';
        gImg.draggable = false; // wie beim Bild-Block: nativen Drag verhindern
        gImg.style.objectFit = block.fit || 'cover';
        gImg.className = 'sb-g-img' + (gi === 0 ? ' on' : '');
        gWrap.appendChild(gImg);
      });
      if (mode === 'editor' && gImgs.length > 1) {
        var gBadge = document.createElement('div');
        gBadge.className = 'sb-g-badge';
        gBadge.textContent = t('block.gallery.badge', '🎞️ {n} Bilder · {s}s', {n: gImgs.length, s: Math.max(2, num(block.interval, 6))});
        gWrap.appendChild(gBadge);
      }
      inner.appendChild(gWrap);
      return inner;
    }

    if (type === 'shape') {
      inner.style.padding = '0';
      var shape = document.createElement('div');
      shape.className = 'sb-shape';
      shape.style.background = block.color || '#5f8cff';
      shape.style.opacity = String(Math.max(5, Math.min(100, num(block.opacity, 100))) / 100);
      if ((block.kind || 'rect') === 'ellipse') {
        shape.style.borderRadius = '50%';
      } else {
        shape.style.borderRadius = (Math.max(0, Math.min(400, num(block.radius, 24))) * sc) + 'px';
      }
      inner.appendChild(shape);
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
        '<div class="sb-w-desc" style="font-size:' + (wFont * 0.8) + 'px">' + escapeHtml(t('common.loading', 'Lädt…')) + '</div>' +
        (block.show_forecast ? '<div class="sb-w-fc" style="font-size:' + (wFont * 0.72) + 'px"></div>' : '');
      return inner;
    }

    if (type === 'rss') {
      if (!block.url) {
        inner.innerHTML = '<div class="sb-block-empty">' + escapeHtml(t('block.rss.empty', 'RSS-Feed – Feed-URL im Editor setzen')) + '</div>';
        return inner;
      }
      inner.style.fontSize = (Math.max(10, num(block.font_size, 36)) * sc) + 'px';
      inner.dataset.rss = '1';
      inner.dataset.url = block.url;
      inner.dataset.count = String(Math.max(1, Math.min(15, num(block.count, 5))));
      inner.dataset.showTime = block.show_time === false ? '' : '1';
      inner.dataset.showSource = block.show_source ? '1' : '';
      inner.innerHTML = '<div class="sb-rss"><div class="sb-rss-loading">' + escapeHtml(t('block.rss.loading', '📡 Lädt…')) + '</div></div>';
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
      track.textContent = block.text || t('block.type.ticker', 'Laufband');
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
        inner.innerHTML = '<div class="sb-webpage-fallback">🌐 <strong>' + escapeHtml(t('block.type.webpage', 'Webseite')) + '</strong>' +
          '<span class="sb-wp-url">' + escapeHtml(block.url || t('block.webpage.no_url', 'Noch keine URL gesetzt')) + '</span>' +
          '<span style="font-size:.72em;opacity:.7">' + escapeHtml(mode === 'editor' ? t('block.webpage.preview_display_only', 'Live-Vorschau erst auf dem Display') : t('block.webpage.no_embed', 'Diese Seite erlaubt keine Einbettung')) + '</span></div>';
      }
      return inner;
    }

    if (type === 'animation') {
      inner.style.padding = '0';
      // Eigene HTML/CSS-Animation in einer SANDBOX (kein same-origin -> isoliert).
      // Live nur im Display/Vorschau; im Editor ein Platzhalter, damit der Block
      // verschiebbar bleibt (ein aktives iframe wuerde die Maus schlucken).
      if (block.html && mode === 'display') {
        var aframe = document.createElement('iframe');
        aframe.setAttribute('sandbox', 'allow-scripts');
        aframe.setAttribute('scrolling', 'no');
        aframe.srcdoc = String(block.html);
        inner.appendChild(aframe);
      } else {
        inner.innerHTML = '<div class="sb-anim-ph">✨ ' + escapeHtml(t('block.type.animation', 'Animation')) +
          '<span>' + escapeHtml(block.html ? t('block.preview_live_hint', 'Live-Vorschau auf dem Display / per „Vorschau“') : t('block.animation.empty', 'HTML/CSS im Editor einfügen')) + '</span></div>';
      }
      return inner;
    }

    if (type === 'video') {
      inner.style.padding = '0';
      var ytid = youtubeId(block.src);
      // Live nur im Display/Vorschau; im Editor Platzhalter (Block bleibt verschiebbar).
      if (block.src && mode === 'display') {
        if (ytid) {
          // YouTube: offizielles Embed mit Autoplay (nur stumm moeglich) + Endlosschleife.
          var yf = document.createElement('iframe');
          yf.src = 'https://www.youtube.com/embed/' + ytid +
            '?autoplay=1&mute=1&loop=1&playlist=' + ytid + '&controls=0&rel=0&playsinline=1&modestbranding=1';
          yf.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
          yf.setAttribute('frameborder', '0');
          inner.appendChild(yf);
        } else {
          var vid = document.createElement('video');
          vid.src = block.src;
          vid.autoplay = true; vid.loop = true; vid.muted = true; vid.defaultMuted = true;
          vid.setAttribute('muted', ''); vid.setAttribute('autoplay', ''); vid.setAttribute('loop', ''); vid.setAttribute('playsinline', '');
          vid.style.objectFit = block.fit || 'cover';
          inner.appendChild(vid);
        }
      } else {
        inner.innerHTML = '<div class="sb-anim-ph">🎬 ' + escapeHtml(t('block.type.video', 'Video')) +
          '<span>' + escapeHtml(block.src ? (ytid ? t('block.video.preview_youtube', 'YouTube – Live-Vorschau auf dem Display / per „Vorschau“') : t('block.preview_live_hint', 'Live-Vorschau auf dem Display / per „Vorschau“')) : t('block.video.empty', 'Datei, URL oder YouTube-Link setzen')) + '</span></div>';
      }
      return inner;
    }

    if (type === 'qrcode') {
      var size = Math.min(num(block.w, 320), num(block.h, 320)) - 40;
      var img = document.createElement('img');
      img.src = qrSrc(block.data, size);
      img.alt = t('block.type.qrcode', 'QR-Code');
      img.draggable = false; // wie beim Bild-Block: nativen Drag verhindern, sonst klemmt das Verschieben
      // QR wird extern erzeugt (api.qrserver.com) -> im Offline-LAN sichtbarer Hinweis statt leerem Kasten.
      img.onerror = function () { inner.innerHTML = '<div class="sb-block-empty">' + escapeHtml(t('block.qrcode.offline', 'QR-Code offline nicht verfügbar')) + '</div>'; };
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

    inner.innerHTML = '<div class="sb-block-empty">' + escapeHtml(t('block.unknown', 'Unbekannter Block')) + '</div>';
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
  var weatherEndpointSaved = null;   // fuer den periodischen Refresh gemerkt
  var weatherRefreshStarted = false; // genau ein Intervall, kein Stacking
  var rssCache = new Map();     // url -> {at, data, err}  (gleiches Muster wie weatherCache)
  var rssPending = new Set();
  var RSS_TTL = 9 * 60 * 1000;
  var rssEndpointSaved = null;
  var rssRefreshStarted = false;

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
        ? t('block.countdown.days', '{n}T {time}', {n: days, time: pad(hours) + ':' + pad(mins) + ':' + pad(secs)})
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
    var fc = el.querySelector('.sb-w-fc');
    if (!data || data.error) {
      // Sichtbarer Offline-Zustand statt dauerhaftem "Laedt…" (typisch im reinen LAN ohne Internet).
      if (emoji) emoji.textContent = '🌐';
      if (temp) temp.textContent = '–';
      if (desc) desc.textContent = t('weather.offline', 'Wetter offline');
      if (fc) fc.innerHTML = '';
      return;
    }
    if (emoji && data.emoji) emoji.textContent = data.emoji;
    if (temp) temp.textContent = data.temp_c + ' °C';
    if (desc) desc.textContent = data.desc || '';
    if (fc) {
      // 3-Tage-Vorschau; aeltere Cache-Antworten ohne forecast-Feld -> Zeile leer lassen.
      var days = Array.isArray(data.forecast) ? data.forecast : [];
      fc.innerHTML = days.map(function (d) {
        return '<div class="sb-w-fc-day">' +
          '<span class="sb-w-fc-name">' + escapeHtml(d.day || '') + '</span>' +
          '<span class="sb-w-fc-emoji">' + escapeHtml(d.emoji || '') + '</span>' +
          '<span class="sb-w-fc-temp">' + escapeHtml(d.tmax != null ? d.tmax : '-') + '°<small>/' + escapeHtml(d.tmin != null ? d.tmin : '-') + '°</small></span>' +
          '</div>';
      }).join('');
    }
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

  // Relative Zeitangabe fuer Feed-Meldungen (deutsch, kurz).
  function rssAge(ts) {
    if (!ts) return '';
    var mins = (Date.now() / 1000 - ts) / 60;
    if (mins < 1) return t('block.rss.age.now', 'gerade eben');
    if (mins < 60) return t('block.rss.age.minutes', 'vor {n} Min.', {n: Math.round(mins)});
    if (mins < 48 * 60) return t('block.rss.age.hours', 'vor {n} Std.', {n: Math.round(mins / 60)});
    var d = new Date(ts * 1000);
    return t('block.rss.age.date', '{d}.{m}.', {d: pad(d.getDate()), m: pad(d.getMonth() + 1)});
  }

  function paintRss(el, data) {
    var box = el.querySelector('.sb-rss');
    if (!box) return;
    if (!data || data.error || !Array.isArray(data.items) || !data.items.length) {
      // Sichtbarer Offline-Zustand statt dauerhaftem "Laedt…" (wie beim Wetter).
      box.innerHTML = '<div class="sb-rss-loading">' + escapeHtml(t('block.rss.offline', '📡 Feed offline')) + '</div>';
      return;
    }
    var count = Math.max(1, Math.min(15, Number(el.dataset.count) || 5));
    var html = '';
    if (el.dataset.showSource && data.source) {
      html += '<div class="sb-rss-src">' + escapeHtml(data.source) + '</div>';
    }
    data.items.slice(0, count).forEach(function (item) {
      html += '<div class="sb-rss-item"><span class="sb-rss-title">' + escapeHtml(item.title || '') + '</span>' +
        (el.dataset.showTime ? '<span class="sb-rss-time">' + escapeHtml(rssAge(item.ts)) + '</span>' : '') +
        '</div>';
    });
    box.innerHTML = html;
  }

  function loadRss(root, endpoint) {
    if (!endpoint) return;
    root.querySelectorAll('[data-rss]').forEach(function (el) {
      var url = el.dataset.url;
      if (!url) return;
      var cached = rssCache.get(url);
      if (cached) paintRss(el, cached.data);
      // Gueltige Daten 9 Min cachen; Fehler nur 1 Min, damit es nach Netz-Rueckkehr schnell heilt.
      if (cached && !cached.err && (Date.now() - cached.at) < RSS_TTL) return;
      if (cached && cached.err && (Date.now() - cached.at) < 60000) return;
      if (rssPending.has(url)) return;
      rssPending.add(url);
      fetch(endpoint + '?url=' + encodeURIComponent(url))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          rssCache.set(url, {at: Date.now(), data: data, err: !data || !!data.error});
          document.querySelectorAll('[data-rss]').forEach(function (node) {
            if (node.dataset.url === url) paintRss(node, data);
          });
        })
        .catch(function () {
          rssCache.set(url, {at: Date.now(), data: {error: true}, err: true});
          document.querySelectorAll('[data-rss]').forEach(function (node) {
            if (node.dataset.url === url) paintRss(node, {error: true});
          });
        })
        .finally(function () { rssPending.delete(url); });
    });
  }

  // Feeds periodisch aktualisieren (alle 15 Min) - wie beim Wetter genau ein Intervall.
  function startRssRefresh() {
    if (rssRefreshStarted || !rssEndpointSaved) return;
    rssRefreshStarted = true;
    setInterval(function () {
      if (rssEndpointSaved) loadRss(document, rssEndpointSaved);
    }, 15 * 60 * 1000);
  }

  // Diashow: Bilder im Block rotieren (Ueberblendung via CSS-Klasse .on).
  // Gleiches Muster wie webpageTimers: alte Intervalle raeumen, neu aufsetzen –
  // applyLive darf beliebig oft laufen (Display-Resize), ohne Timer zu stapeln.
  var galleryTimers = [];
  function setupGalleries(root) {
    galleryTimers.forEach(clearInterval);
    galleryTimers = [];
    root.querySelectorAll('[data-gallery]').forEach(function (wrap) {
      var imgs = wrap.querySelectorAll('.sb-g-img');
      if (imgs.length < 2) return;
      var idx = 0;
      imgs.forEach(function (im, i) { im.classList.toggle('on', i === 0); });
      var ms = Math.max(2, Number(wrap.dataset.interval) || 6) * 1000;
      galleryTimers.push(setInterval(function () {
        imgs[idx].classList.remove('on');
        idx = (idx + 1) % imgs.length;
        imgs[idx].classList.add('on');
      }, ms));
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

  // Wetter periodisch aktualisieren (alle 15 Min), damit ein statisches 24/7-
  // Display nicht auf dem Wert vom Seitenaufruf festhaengt. Genau ein Intervall.
  function startWeatherRefresh() {
    if (weatherRefreshStarted || !weatherEndpointSaved) return;
    weatherRefreshStarted = true;
    setInterval(function () {
      if (weatherEndpointSaved) loadWeather(document, weatherEndpointSaved);
    }, 15 * 60 * 1000);
  }

  // Startet/aktualisiert alle Live-Elemente unter root.
  function applyLive(root, opts) {
    opts = opts || {};
    startGlobalLive();
    if (opts.weatherEndpoint) {
      weatherEndpointSaved = opts.weatherEndpoint;
      loadWeather(root, opts.weatherEndpoint);
      startWeatherRefresh();
    }
    if (opts.rssEndpoint) {
      rssEndpointSaved = opts.rssEndpoint;
      loadRss(root, opts.rssEndpoint);
      startRssRefresh();
    }
    setupWebpageRefresh(root);
    setupTickers(root);
    setupGalleries(root);
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
