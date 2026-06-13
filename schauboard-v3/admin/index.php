<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
schauboard_ensure_data_files();

$version = schauboard_version();
$storedHash = schauboard_load_password_hash();
$needsSetup = $storedHash === null;

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($needsSetup && isset($_POST['setup_password'])) {
    $password = (string) ($_POST['setup_password'] ?? '');
    $confirm = (string) ($_POST['setup_password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $setupError = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $confirm) {
        $setupError = 'Die beiden Passwoerter stimmen nicht ueberein.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!$hash || !schauboard_store_password_hash($hash)) {
            $setupError = 'Die Passwortdatei konnte nicht gespeichert werden.';
        } else {
            session_regenerate_id(true); // gegen Session-Fixation
            $_SESSION['schauboard_admin_auth'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

if (!$needsSetup && isset($_POST['login_password'])) {
    if (schauboard_password_matches((string) $_POST['login_password'], $storedHash)) {
        session_regenerate_id(true); // gegen Session-Fixation
        $_SESSION['schauboard_admin_auth'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $loginError = true;
}

if ($needsSetup || !schauboard_session_is_authenticated()) {
    require __DIR__ . '/auth_gate.php';
    exit;
}

$section = (string) ($_GET['section'] ?? 'editor');
$validSections = ['editor', 'playlists', 'displays', 'schedules', 'settings'];
if (!in_array($section, $validSections, true)) {
    $section = 'editor';
}

$settings = schauboard_read_dataset('settings');
$slides = schauboard_read_dataset('slides');
$playlists = schauboard_read_dataset('playlists');
$displays = schauboard_read_dataset('displays');
$schedules = schauboard_read_dataset('schedules');
$heartbeats = schauboard_read_json_file(dirname(__DIR__) . '/data/heartbeats.json', []);

$sectionTitles = [
    'editor' => 'Folien',
    'playlists' => 'Playlists',
    'displays' => 'Displays',
    'schedules' => 'Zeitpläne',
    'settings' => 'Einstellungen',
];
$sectionHints = [
    'editor' => 'Folien gestalten: Block auf die Bühne ziehen, anklicken zum Bearbeiten, ziehen zum Verschieben.',
    'playlists' => 'Folien zu Playlists bündeln. Ein Display zeigt immer eine Playlist.',
    'displays' => 'Deine Bildschirme: Name vergeben, URL öffnen, Playlist zuweisen – fertig.',
    'schedules' => 'Optional: zu bestimmten Zeiten automatisch eine andere Playlist zeigen.',
    'settings' => 'Globale Einstellungen, Branding und Wartungsmodus.',
];

$jsState = [
    'settings' => $settings,
    'slides' => $slides,
    'playlists' => $playlists,
    'displays' => $displays,
    'schedules' => $schedules,
    'heartbeats' => (object) $heartbeats,
    'offlineTimeoutMin' => (int) ($settings['system']['offline_timeout_minutes'] ?? 5),
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($version['name'] ?? 'Schauboard') . ' Admin') ?></title>
<link rel="stylesheet" href="../assets/blocks.css">
<style>
:root{--bg:#06111c;--bg2:#0b1524;--panel:#0f1a2b;--line:rgba(255,255,255,.08);--text:#f5f7fb;--muted:#93a8c2;--accent:#5f8cff;--accent2:#73dfc4;--danger:#ff8ea1;--ok:#73dfc4;--shadow:0 32px 90px rgba(0,0,0,.35)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{height:100vh;overflow:hidden;padding:10px;color:var(--text);font-family:"Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 0% 0%, rgba(95,140,255,.16), transparent 25%),radial-gradient(circle at 100% 0%, rgba(115,223,196,.10), transparent 28%),linear-gradient(180deg,var(--bg) 0%,var(--bg2) 100%)}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.shell{max-width:1560px;height:100%;margin:0 auto;display:flex;flex-direction:column;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01)),var(--panel);box-shadow:var(--shadow);overflow:hidden}
header.appbar{flex:0 0 auto;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:8px 16px;border-bottom:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,.03), transparent)}
.brand{display:flex;align-items:center;gap:10px}
.brand-logo{height:30px;width:auto;display:block}
.brand .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(95,140,255,.16);border:1px solid rgba(95,140,255,.22);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#d8e5ff}
.appbar .actions{display:flex;gap:8px;align-items:center}
button.btn,.btn{min-height:38px;padding:9px 14px;border:none;border-radius:12px;cursor:pointer;font-weight:700;font-size:13px;transition:transform .16s ease, box-shadow .16s ease, background .16s ease;background:rgba(255,255,255,.08);color:var(--text);border:1px solid rgba(255,255,255,.06)}
button.btn:hover,.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.13)}
.btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06111c;border:none;box-shadow:0 14px 30px rgba(95,140,255,.24)}
.btn.danger{background:rgba(255,142,161,.14);color:#ffd8df;border:1px solid rgba(255,142,161,.2)}
.btn.small{min-height:30px;padding:5px 10px;font-size:12px;border-radius:9px}
.layout{flex:1;min-height:0;display:grid;grid-template-columns:180px 1fr}
.sidebar{padding:12px;border-right:1px solid var(--line);background:rgba(255,255,255,.02);overflow:auto}
.nav{display:grid;gap:5px}
.nav a{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:11px;border:1px solid transparent;color:#d7e2f2;font-size:13px;font-weight:700}
.nav a .ic{font-size:15px}
.nav a:hover{background:rgba(255,255,255,.04);border-color:rgba(95,140,255,.16)}
.nav a.active{background:linear-gradient(135deg, rgba(95,140,255,.18), rgba(115,223,196,.10));border-color:rgba(95,140,255,.25)}
.nav .view-link{margin-top:14px;color:var(--accent2)}
.content{flex:1;min-width:0;padding:12px 16px;display:flex;flex-direction:column;gap:10px;overflow:hidden}
.section-head{flex:0 0 auto;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.section-head h2{font-size:18px;letter-spacing:-.03em}
.section-head p{color:var(--muted);font-size:12px;margin-top:2px;max-width:680px;line-height:1.4}
.card{padding:16px;border-radius:16px;border:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015))}
.content>.card{flex:1;min-height:0;overflow:auto}
.content>.editor-card{overflow:hidden;padding:0}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.spread{justify-content:space-between}
label.field{display:grid;gap:7px;color:var(--muted);font-size:12px;font-weight:700}
input,select,textarea{width:100%;min-height:38px;padding:9px 11px;border-radius:11px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);outline:none}
textarea{min-height:80px;resize:vertical}
input:focus,select:focus,textarea:focus{border-color:rgba(95,140,255,.28);box-shadow:0 0 0 4px rgba(95,140,255,.12)}
.checkbox{display:flex;align-items:center;gap:9px;color:var(--text);font-weight:600;font-size:13px}
.checkbox input{width:18px;min-height:auto;height:18px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.form-grid .full{grid-column:1 / -1}
.muted{color:var(--muted);font-size:12px;line-height:1.5}
code{font-family:Consolas,monospace;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:7px;font-size:12px}

/* ===== Editor ===== */
.editor-card{display:flex}
.editor-workspace{flex:1;min-height:0;display:grid;grid-template-columns:180px minmax(0,1fr);gap:0;border:1px solid rgba(255,255,255,.06);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.02)}
.editor-sidebar{border-right:1px solid rgba(255,255,255,.06);background:rgba(12,18,32,.7);display:grid;grid-template-rows:1fr auto auto;min-height:0}
.editor-side-section{padding:12px;border-bottom:1px solid rgba(255,255,255,.06);overflow:auto}
.editor-side-section h4{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7f8ead;margin-bottom:10px}
.editor-side-list{display:grid;gap:7px}
.slide-item-btn,.block-pill{width:100%;padding:9px 11px;border-radius:11px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);color:var(--text);text-align:left;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px}
.slide-item-btn.active,.block-pill.active{border-color:rgba(95,140,255,.3);background:linear-gradient(135deg, rgba(95,140,255,.2), rgba(115,223,196,.08))}
.slide-item-btn strong,.block-pill strong{display:block;font-size:13px}
.slide-item-btn small,.block-pill small{display:block;color:var(--muted);margin-top:3px;font-size:11px}
.block-pill{padding:0;overflow:hidden}
.block-pill-info{flex:1;min-width:0;background:transparent;border:none;color:var(--text);text-align:left;cursor:pointer;padding:9px 11px}
.layer-ctrl{display:flex;flex-direction:column;flex:0 0 auto;border-left:1px solid rgba(255,255,255,.08)}
.layer-ctrl button{flex:1;min-height:0;width:28px;border:none;background:rgba(255,255,255,.04);color:var(--muted);cursor:pointer;font-size:9px;padding:2px 0}
.layer-ctrl button:hover:not(:disabled){background:rgba(95,140,255,.22);color:#fff}
.layer-ctrl button:disabled{opacity:.25;cursor:default}
.editor-main{display:grid;grid-template-rows:auto minmax(0,1fr) auto;background:rgba(17,24,40,.42);min-height:0}
.editor-toolbar{padding:7px 12px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(19,26,43,.72)}
.tool-palette{display:flex;gap:5px;flex-wrap:wrap}
.tool-btn{padding:5px 8px;border-radius:9px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);font-size:11px;font-weight:700;cursor:grab;display:flex;align-items:center;gap:5px}
.tool-btn:hover{background:rgba(255,255,255,.09);border-color:rgba(95,140,255,.25)}
.tool-btn .ic{font-size:13px}
.toolbar-sep{width:1px;height:24px;background:rgba(255,255,255,.1)}
.toolbar-group{display:flex;gap:8px;align-items:center}
.toolbar-group label{display:flex;gap:6px;align-items:center;font-size:11px;color:var(--muted);font-weight:600}
.toolbar-group input{min-height:30px;width:auto;max-width:140px;padding:5px 9px}
.toolbar-group input[type=number]{width:64px}
.editor-stage-wrap{position:relative;min-height:0;padding:12px;background-image:linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px),linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px);background-size:40px 40px;background-position:center;display:flex;align-items:center;justify-content:center;overflow:hidden}
.studio-canvas{height:100%;aspect-ratio:16/9;max-width:100%;width:auto;position:relative;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:#1a1a2e;box-shadow:inset 0 1px 0 rgba(255,255,255,.05);overflow:hidden}
.studio-canvas.drag-over{border-color:rgba(115,223,196,.55);box-shadow:0 0 0 4px rgba(115,223,196,.14)}
.studio-canvas .sb-block{cursor:move;border:1px solid transparent;border-radius:10px}
.studio-canvas .sb-block.active{border-color:rgba(115,223,196,.65);box-shadow:0 0 0 2px rgba(115,223,196,.2)}
.block-overlay-menu{position:absolute;top:6px;right:6px;display:flex;gap:5px;z-index:6}
.block-overlay-menu button{min-height:auto;width:28px;height:28px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(8,13,24,.82);color:#e7eefc;font-size:13px;cursor:pointer;display:grid;place-items:center;padding:0}
.block-overlay-menu button:hover{background:rgba(16,24,40,.98)}
.block-overlay-menu .danger{color:#ffd8df;border-color:rgba(255,142,161,.25)}
.resize-handle{position:absolute;right:5px;bottom:5px;width:14px;height:14px;border-radius:999px;border:2px solid rgba(11,18,31,.9);background:linear-gradient(135deg, var(--accent), var(--accent2));z-index:7;cursor:nwse-resize}
.snap-line{position:absolute;background:rgba(115,223,196,.6);pointer-events:none;z-index:5}
.snap-line.v{top:0;bottom:0;width:1px}.snap-line.h{left:0;right:0;height:1px}
.canvas-empty{position:absolute;inset:0;display:grid;place-items:center;color:rgba(255,255,255,.5);font-size:15px;text-align:center;padding:30px}
.editor-bottombar{padding:7px 12px;border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:rgba(19,26,43,.72)}
.editor-sidebar{grid-template-rows:minmax(0,1.4fr) minmax(0,1fr)!important}

/* ===== Cards/lists for playlists/displays/schedules ===== */
.item-grid{display:grid;gap:12px}
.item-card{padding:16px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.025);display:grid;gap:12px}
.item-card .head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.item-card .head strong{font-size:15px}
.status-dot{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:var(--muted)}
.status-dot .dot{width:9px;height:9px;border-radius:999px;background:#5b6b82}
.status-dot.online{color:var(--ok)}.status-dot.online .dot{background:var(--ok);box-shadow:0 0 10px rgba(115,223,196,.6)}
.url-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.url-pill{flex:1;min-width:200px;font-family:Consolas,monospace;font-size:12px;color:#cbd6ea;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:9px 11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.slide-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.slide-pick{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);font-size:13px;cursor:pointer}
.slide-pick input{width:16px;height:16px;min-height:auto}
.day-picker{display:flex;gap:6px;flex-wrap:wrap}
.day-chip{padding:7px 11px;border-radius:999px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);font-size:12px;font-weight:700;cursor:pointer;user-select:none}
.day-chip.on{background:linear-gradient(135deg, rgba(95,140,255,.25), rgba(115,223,196,.12));border-color:rgba(95,140,255,.35)}

/* ===== Modal ===== */
.modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(2,7,14,.74);backdrop-filter:blur(10px);z-index:80}
.modal-backdrop.open{display:flex}
.modal-card{width:min(760px,100%);max-height:90vh;overflow:auto;padding:22px;border-radius:22px;border:1px solid rgba(255,255,255,.1);background:linear-gradient(180deg, rgba(20,31,51,.97), rgba(12,20,34,.99));box-shadow:0 36px 80px rgba(0,0,0,.45);display:grid;gap:16px}
.modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.modal-head h4{font-size:20px;letter-spacing:-.02em}
.modal-actions{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.field-hidden{display:none !important}
.table-editor{display:grid;gap:10px}
.table-grid{overflow:auto;border:1px solid rgba(255,255,255,.08);border-radius:10px}
.table-grid table{border-collapse:collapse;width:100%}
.table-grid td{padding:3px}
.table-grid input{min-height:34px;min-width:90px;border-radius:7px}
.paste-zone{min-height:60px;border:1px dashed rgba(115,223,196,.4);border-radius:10px;background:rgba(115,223,196,.05);color:var(--muted);font-size:13px}

/* ===== Preview overlay ===== */
.preview-overlay{position:fixed;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:28px;background:rgba(2,7,14,.92);backdrop-filter:blur(8px);z-index:90}
.preview-overlay.open{display:flex}
.preview-frame{width:min(1280px,92vw);aspect-ratio:16/9;border-radius:14px;overflow:hidden;border:1px solid rgba(255,255,255,.1);background:#000;box-shadow:0 30px 80px rgba(0,0,0,.5)}
.preview-frame iframe{width:100%;height:100%;border:none;display:block}

/* ===== Toasts ===== */
.toast-wrap{position:fixed;right:18px;bottom:18px;display:grid;gap:10px;z-index:120}
.toast{padding:12px 16px;border-radius:13px;border:1px solid rgba(255,255,255,.12);background:rgba(16,24,40,.96);color:#e7eefc;font-size:13px;font-weight:600;box-shadow:0 18px 44px rgba(0,0,0,.4);min-width:200px;animation:toast-in .2s ease}
.toast.ok{border-color:rgba(115,223,196,.4)}
.toast.err{border-color:rgba(255,142,161,.45);color:#ffd8df}
@keyframes toast-in{from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1}}
@media (max-width:1100px){
  body{height:auto;overflow:auto}
  .shell{height:auto}
  .layout{grid-template-columns:1fr}
  .sidebar{border-right:none;border-bottom:1px solid var(--line);overflow:visible}
  .nav{grid-auto-flow:column;overflow:auto}
  .content{overflow:visible}
  .content>.card{overflow:visible}
  .editor-card{display:block}
  .editor-workspace{grid-template-columns:1fr;display:block}
  .editor-stage-wrap{min-height:56vh}
  .form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="shell">
  <header class="appbar">
    <div class="brand">
      <img src="assets/schauboard-logo.png" alt="Schauboard" class="brand-logo">
      <span class="badge"><?= htmlspecialchars($version['label'] ?? 'v3.0.0') ?></span>
    </div>
    <div class="actions">
      <a class="btn" href="../?display=default" target="_blank" rel="noreferrer">▶ Display ansehen</a>
      <form method="post" style="margin:0"><button type="submit" class="btn" name="logout" value="1">Abmelden</button></form>
    </div>
  </header>
  <div class="layout">
    <aside class="sidebar">
      <nav class="nav">
        <?php
        $icons = ['editor' => '🎨', 'playlists' => '🎞️', 'displays' => '🖥️', 'schedules' => '🕒', 'settings' => '⚙️'];
        foreach ($sectionTitles as $key => $label): ?>
          <a href="?section=<?= urlencode($key) ?>" class="<?= $section === $key ? 'active' : '' ?>"><span class="ic"><?= $icons[$key] ?? '•' ?></span><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </aside>
    <main class="content">
      <div class="section-head">
        <div>
          <h2><?= htmlspecialchars($sectionTitles[$section]) ?></h2>
          <p><?= htmlspecialchars($sectionHints[$section]) ?></p>
        </div>
        <div class="row">
          <?php if ($section === 'editor'): ?>
            <button type="button" class="btn" id="previewSlide">👁 Vorschau</button>
            <button type="button" class="btn primary" id="saveSlides">💾 Speichern</button>
          <?php elseif ($section === 'playlists'): ?>
            <button type="button" class="btn" id="addPlaylist">+ Playlist</button>
            <button type="button" class="btn primary" id="savePlaylists">💾 Speichern</button>
          <?php elseif ($section === 'displays'): ?>
            <button type="button" class="btn" id="addDisplay">+ Display</button>
            <button type="button" class="btn primary" id="saveDisplays">💾 Speichern</button>
          <?php elseif ($section === 'schedules'): ?>
            <button type="button" class="btn" id="addSchedule">+ Zeitplan</button>
            <button type="button" class="btn primary" id="saveSchedules">💾 Speichern</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($section === 'editor'): ?>
        <section class="card editor-card">
          <div class="editor-workspace">
            <aside class="editor-sidebar">
              <section class="editor-side-section">
                <h4>Folien</h4>
                <div id="slidesList" class="editor-side-list"></div>
                <button type="button" class="btn small" id="addSlide" style="width:100%;margin-top:10px;">+ Folie</button>
              </section>
              <section class="editor-side-section">
                <h4>Ebenen</h4>
                <div id="blockList" class="editor-side-list"></div>
              </section>
            </aside>
            <section class="editor-main">
              <div class="editor-toolbar">
                <div class="tool-palette" id="toolPalette"></div>
                <div class="toolbar-sep"></div>
                <div class="toolbar-group">
                  <label>Name <input type="text" id="slideName" placeholder="Folienname"></label>
                  <label>Dauer <input type="number" min="2" max="600" id="slideDuration">s</label>
                </div>
              </div>
              <div class="editor-stage-wrap">
                <div id="studioCanvas" class="studio-canvas"></div>
              </div>
              <div class="editor-bottombar">
                <div class="muted" id="editorMeta">Noch keine Folie ausgewählt.</div>
                <details>
                  <summary class="muted" style="cursor:pointer;">Folie anpassen</summary>
                  <div class="row" style="margin-top:10px;">
                    <label class="field">ID<input type="text" id="slideId"></label>
                    <label class="field">Hintergrundfarbe<input type="text" id="slideBgColor" placeholder="#1a1a2e"></label>
                    <label class="field">Hintergrundbild (URL)<input type="text" id="slideBgImage" placeholder="optional"></label>
                  </div>
                </details>
              </div>
            </section>
          </div>
        </section>
      <?php elseif ($section === 'settings'): ?>
        <section class="card">
          <form id="settingsForm" class="form-grid">
            <label class="field">Produktname<input type="text" name="branding_name" value="<?= htmlspecialchars($settings['branding']['name'] ?? 'Schauboard') ?>"></label>
            <label class="field">Zeitzone<input type="text" name="timezone" value="<?= htmlspecialchars($settings['system']['timezone'] ?? 'Europe/Zurich') ?>"></label>
            <label class="field">Standard-Dauer pro Folie (s)<input type="number" min="2" max="600" name="default_slide_duration" value="<?= (int) ($settings['system']['default_slide_duration'] ?? 10) ?>"></label>
            <label class="field">Standard-Übergang
              <select name="default_transition">
                <?php foreach (['fade' => 'Überblenden', 'slide-left' => 'Schieben (links)', 'slide-up' => 'Schieben (hoch)', 'zoom' => 'Zoom', 'none' => 'Ohne'] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= (($settings['system']['default_transition'] ?? 'fade') === $val) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">Offline-Schwelle (Min.)<input type="number" min="1" max="120" name="offline_timeout_minutes" value="<?= (int) ($settings['system']['offline_timeout_minutes'] ?? 5) ?>"></label>
            <label class="field">Wetter-Standardort<input type="text" name="weather_location" value="<?= htmlspecialchars($settings['weather']['location'] ?? 'Zurich,CH') ?>"></label>
            <label class="checkbox full"><input type="checkbox" name="weather_enabled" <?= !empty($settings['weather']['enabled']) ? 'checked' : '' ?>> Wetter-Module aktivieren</label>
            <label class="checkbox full"><input type="checkbox" name="maintenance_enabled" <?= !empty($settings['maintenance']['enabled']) ? 'checked' : '' ?>> Wartungsmodus (zeigt allen Displays einen Hinweis)</label>
            <label class="field full">Wartungsmeldung<textarea name="maintenance_message"><?= htmlspecialchars($settings['maintenance']['message'] ?? '') ?></textarea></label>
            <div class="full row spread">
              <span class="muted">Speichert nach <code>data/settings.json</code></span>
              <button type="submit" class="btn primary">💾 Einstellungen speichern</button>
            </div>
          </form>
        </section>
      <?php else: ?>
        <section class="card">
          <div id="itemContainer" class="item-grid"></div>
          <div id="emptyHint" class="muted" style="display:none;margin-top:10px;"></div>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<!-- Block-Editor-Modal -->
<div id="blockModal" class="modal-backdrop">
  <div class="modal-card">
    <div class="modal-head">
      <div><h4 id="modalTitle">Block bearbeiten</h4><div class="muted" id="modalSub"></div></div>
      <button type="button" class="btn" id="closeModal">Schliessen</button>
    </div>
    <div class="form-grid">
      <label class="field" data-f="type">Typ
        <select id="mType"></select>
      </label>
      <label class="field full" data-f="text">Inhalt<textarea id="mText"></textarea></label>
      <label class="field" data-f="src">Bild-URL / Pfad
        <input type="text" id="mSrc">
      </label>
      <label class="field" data-f="upload">Bild hochladen
        <input type="file" id="mUpload" accept="image/*">
      </label>
      <label class="field" data-f="fit">Darstellung
        <select id="mFit"><option value="cover">Füllen (cover)</option><option value="contain">Einpassen (contain)</option><option value="fill">Strecken</option></select>
      </label>
      <label class="field" data-f="city">Ort<input type="text" id="mCity"></label>
      <label class="field" data-f="clock_format">Format
        <select id="mClockFormat"><option value="HH:MM">HH:MM</option><option value="HH:MM:SS">HH:MM:SS</option></select>
      </label>
      <label class="checkbox" data-f="show_date"><input type="checkbox" id="mShowDate"> Datum anzeigen</label>
      <label class="field" data-f="speed">Tempo (10–200)<input type="number" id="mSpeed" min="10" max="200"></label>
      <label class="field" data-f="bg">Hintergrund<input type="text" id="mBg"></label>
      <label class="field" data-f="url">Webseiten-URL<input type="text" id="mUrl" placeholder="https://…"></label>
      <label class="field" data-f="refresh_minutes">Neu laden alle … Min. (0 = nie)<input type="number" id="mRefresh" min="0" max="1440"></label>
      <label class="field" data-f="zoom">Zoom (%)<input type="number" id="mZoom" min="25" max="200"></label>
      <label class="field" data-f="data">QR-Inhalt (URL/Text)<input type="text" id="mData"></label>
      <label class="field" data-f="qlabel">Beschriftung<input type="text" id="mQLabel"></label>
      <label class="field" data-f="target">Zieltermin<input type="datetime-local" id="mTarget"></label>
      <label class="field" data-f="clabel">Beschriftung<input type="text" id="mCLabel"></label>
      <label class="field" data-f="font_size">Schriftgrösse<input type="number" id="mFont" min="10" max="400"></label>
      <label class="field" data-f="color">Farbe<input type="text" id="mColor"></label>
      <label class="field" data-f="align">Ausrichtung
        <select id="mAlign"><option value="left">Links</option><option value="center">Mitte</option><option value="right">Rechts</option></select>
      </label>
      <label class="checkbox" data-f="bold"><input type="checkbox" id="mBold"> Fett</label>

      <div class="full table-editor" data-f="table">
        <div class="row spread">
          <strong style="font-size:14px;">Tabelle</strong>
          <div class="row">
            <button type="button" class="btn small" id="tblAddRow">+ Zeile</button>
            <button type="button" class="btn small" id="tblAddCol">+ Spalte</button>
            <button type="button" class="btn small" id="tblDelRow">− Zeile</button>
            <button type="button" class="btn small" id="tblDelCol">− Spalte</button>
          </div>
        </div>
        <div id="tblGrid" class="table-grid"></div>
        <label class="field">Aus Excel einfügen – hier hinein klicken und Strg+V
          <textarea id="tblPaste" class="paste-zone" placeholder="Zellen in Excel markieren, kopieren, hier einfügen…"></textarea>
        </label>
        <div class="form-grid">
          <label class="field">Kopfzeile-Hintergrund<input type="text" id="mHeaderBg"></label>
          <label class="field">Kopfzeile-Farbe<input type="text" id="mHeaderColor"></label>
          <label class="field">Zellen-Farbe<input type="text" id="mCellColor"></label>
          <label class="field">Rahmen-Farbe<input type="text" id="mBorderColor"></label>
        </div>
      </div>

      <details class="full" data-f="advanced">
        <summary class="muted" style="cursor:pointer;">Position & Grösse</summary>
        <div class="form-grid" style="margin-top:12px;">
          <label class="field">X<input type="number" id="mX" min="0" max="1900"></label>
          <label class="field">Y<input type="number" id="mY" min="0" max="1060"></label>
          <label class="field">Breite<input type="number" id="mW" min="40" max="1920"></label>
          <label class="field">Höhe<input type="number" id="mH" min="40" max="1080"></label>
        </div>
      </details>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn danger" id="deleteBlock">Block entfernen</button>
      <button type="button" class="btn primary" id="applyBlock">Übernehmen</button>
    </div>
  </div>
</div>

<!-- Live-Vorschau -->
<div id="previewOverlay" class="preview-overlay">
  <div class="row" style="width:min(1280px,92vw);justify-content:space-between;">
    <strong>Live-Vorschau (aktuelle Folie)</strong>
    <button type="button" class="btn" id="closePreview">✕ Schliessen</button>
  </div>
  <div class="preview-frame"><iframe id="previewFrame" title="Vorschau"></iframe></div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script src="../assets/blocks.js"></script>
<script>
const APP = <?= json_encode($jsState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const SECTION = <?= json_encode($section) ?>;
const ROOT = new URL('../', location.href).href;
const WEATHER_ENDPOINT = '../api/weather.php';
const B = window.SchauboardBlocks;

/* ===== Helpers ===== */
function toast(msg, kind) {
  const wrap = document.getElementById('toastWrap');
  const el = document.createElement('div');
  el.className = 'toast ' + (kind || 'ok');
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; el.style.transition = 'all .3s ease'; }, 2600);
  setTimeout(() => el.remove(), 3000);
}
async function postJson(url, payload) {
  const res = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
  let data = null;
  try { data = await res.json(); } catch (e) { throw new Error('Unerwartete Server-Antwort.'); }
  if (!res.ok || !data || data.ok !== true) throw new Error(data && data.error ? data.error : 'Speichern fehlgeschlagen.');
  return data;
}
function esc(v) { return B.escapeHtml(v); }
function slug(v) { return String(v || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, ''); }
function uid(p) { return p + Math.random().toString(36).slice(2, 8); }
function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

/* ===== State ===== */
const state = {
  slides: JSON.parse(JSON.stringify(APP.slides || [])),
  playlists: JSON.parse(JSON.stringify(APP.playlists || [])),
  displays: JSON.parse(JSON.stringify(APP.displays || [])),
  schedules: JSON.parse(JSON.stringify(APP.schedules || [])),
  heartbeats: APP.heartbeats || {},
  selectedSlideId: null,
  selectedBlockId: null,
  modalBlockId: null,
  tableDraft: [],
  drag: null,
  resize: null,
  snap: {x: [], y: []},
};

<?php require __DIR__ . '/editor.js.php'; ?>

/* ===== Section bootstrap ===== */
if (SECTION === 'editor') initEditor();
if (SECTION === 'playlists') initPlaylists();
if (SECTION === 'displays') initDisplays();
if (SECTION === 'schedules') initSchedules();
if (SECTION === 'settings') initSettings();
</script>
</body>
</html>
