/*
 * Schauboard – Display-Controller.
 * Rendert die aktive Playlist als Slideshow ueber die gemeinsame Block-Engine
 * (blocks.js), rotiert Folien mit Transition, sendet Heartbeats und pollt auf
 * Aenderungen aus dem Admin (Live-Reload).
 */
(function () {
  'use strict';

  var cfg = window.SCHAUBOARD_DISPLAY || {};
  var slides = Array.isArray(cfg.slides) ? cfg.slides : [];
  var stage = document.getElementById('sbStage');
  var Blocks = window.SchauboardBlocks;
  if (!stage || !Blocks) return;

  var transition = cfg.transition || 'fade';
  var transitionMs = 600;
  var defaultDuration = Number(cfg.defaultDuration || 10);
  var preview = !!cfg.preview;

  // Eine Folie als .sb-stage-Ebene rendern.
  function buildSlide(slide) {
    var layer = document.createElement('div');
    layer.className = 'sb-stage sb-slide';
    layer.style.position = 'absolute';
    layer.style.inset = '0';
    layer.style.opacity = '0';
    layer.style.transition = 'opacity ' + transitionMs + 'ms ease, transform ' + transitionMs + 'ms ease';
    var bgColor = slide.bg_color || '#1a1a2e';
    layer.style.background = slide.bg_image
      ? bgColor + ' url(' + slide.bg_image + ') center/cover no-repeat'
      : bgColor;
    (slide.blocks || []).forEach(function (block) {
      layer.appendChild(Blocks.render(block, 'display'));
    });
    return layer;
  }

  if (!slides.length) {
    var empty = document.createElement('div');
    empty.className = 'sb-empty';
    empty.textContent = cfg.emptyMessage || 'Keine aktive Folie';
    stage.appendChild(empty);
    return;
  }

  var current = 0;
  var layers = slides.map(buildSlide);
  layers.forEach(function (layer) { stage.appendChild(layer); });
  layers[0].style.opacity = '1';
  Blocks.applyLive(stage, {weatherEndpoint: cfg.weatherEndpoint});

  function showSlide(next) {
    if (next === current || !layers[next]) return;
    var outEl = layers[current];
    var inEl = layers[next];
    if (transition === 'none') {
      outEl.style.opacity = '0';
      inEl.style.opacity = '1';
    } else if (transition === 'zoom') {
      outEl.style.opacity = '0';
      outEl.style.transform = 'scale(1.04)';
      inEl.style.transform = 'scale(0.98)';
      inEl.style.opacity = '1';
      requestAnimationFrame(function () { inEl.style.transform = 'scale(1)'; });
    } else if (transition === 'slide-left') {
      outEl.style.opacity = '0';
      outEl.style.transform = 'translateX(-4%)';
      inEl.style.transform = 'translateX(4%)';
      inEl.style.opacity = '1';
      requestAnimationFrame(function () { inEl.style.transform = 'translateX(0)'; });
    } else if (transition === 'slide-up') {
      outEl.style.opacity = '0';
      outEl.style.transform = 'translateY(-4%)';
      inEl.style.transform = 'translateY(4%)';
      inEl.style.opacity = '1';
      requestAnimationFrame(function () { inEl.style.transform = 'translateY(0)'; });
    } else {
      outEl.style.opacity = '0';
      inEl.style.opacity = '1';
    }
    setTimeout(function () { outEl.style.transform = ''; }, transitionMs);
    current = next;
  }

  function scheduleNext() {
    if (preview || layers.length < 2) return;
    var duration = Number(slides[current] && slides[current].duration) || defaultDuration;
    setTimeout(function () {
      var next = (current + 1) % layers.length;
      showSlide(next);
      scheduleNext();
    }, Math.max(2, duration) * 1000);
  }
  scheduleNext();

  // Heartbeat: meldet das Display regelmaessig als online.
  if (!preview && cfg.heartbeatEndpoint && cfg.displayId) {
    var beat = function () {
      fetch(cfg.heartbeatEndpoint + '?display=' + encodeURIComponent(cfg.displayId), {cache: 'no-store'}).catch(function () {});
    };
    beat();
    setInterval(beat, 60 * 1000);
  }

  // Live-Reload: pollt die Versions-Signatur und laedt bei Aenderung neu.
  if (!preview && cfg.revisionEndpoint) {
    var lastRev = cfg.revision || '';
    setInterval(function () {
      fetch(cfg.revisionEndpoint + '?_=' + Date.now(), {cache: 'no-store'})
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.revision && lastRev && data.revision !== lastRev) {
            location.reload();
          }
          if (data && data.revision) lastRev = data.revision;
        })
        .catch(function () {});
    }, 5000);
  }
})();
