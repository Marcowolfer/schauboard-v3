<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

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
            $_SESSION['schauboard_admin_auth'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

if (!$needsSetup && isset($_POST['login_password'])) {
    if (schauboard_password_matches((string) $_POST['login_password'], $storedHash)) {
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
$validSections = ['editor', 'displays', 'settings'];
if (!in_array($section, $validSections, true)) {
    $section = 'editor';
}

$settings = schauboard_read_dataset('settings');
$slides = schauboard_read_dataset('slides');
$playlists = schauboard_read_dataset('playlists');
$displays = schauboard_read_dataset('displays');
$schedules = schauboard_read_dataset('schedules');
$rules = schauboard_read_dataset('rules');

$sectionTitles = [
    'editor' => 'Editor',
    'settings' => 'Einstellungen',
    'displays' => 'Displays',
];

$jsState = [
    'settings' => $settings,
    'slides' => $slides,
    'playlists' => $playlists,
    'displays' => $displays,
    'schedules' => $schedules,
    'rules' => $rules,
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($version['name'] ?? 'Schauboard') . ' Admin') ?></title>
<style>
:root{--bg:#06111c;--bg2:#0b1524;--panel:#0f1a2b;--line:rgba(255,255,255,.08);--text:#f5f7fb;--muted:#93a8c2;--accent:#5f8cff;--accent2:#73dfc4;--danger:#ff8ea1;--shadow:0 32px 90px rgba(0,0,0,.35)}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;padding:20px;color:var(--text);font-family:"Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 0% 0%, rgba(95,140,255,.16), transparent 25%),radial-gradient(circle at 100% 0%, rgba(115,223,196,.10), transparent 28%),linear-gradient(180deg,var(--bg) 0%,var(--bg2) 100%)}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.shell{max-width:1500px;margin:0 auto;border:1px solid var(--line);border-radius:24px;background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01)),var(--panel);box-shadow:var(--shadow);overflow:hidden}
header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,.03), transparent)}
.badge{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;background:rgba(95,140,255,.16);border:1px solid rgba(95,140,255,.22);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#d8e5ff;margin-bottom:10px}
h1{font-size:28px;letter-spacing:-.05em;margin-bottom:4px}
p{color:var(--muted);font-size:13px;line-height:1.45}
.actions{display:flex;gap:8px;align-items:center}
.actions button,.ghost-btn,.primary-btn,.danger-btn{min-height:36px;padding:9px 13px;border:none;border-radius:12px;cursor:pointer;font-weight:700;transition:transform .16s ease, box-shadow .16s ease, background .16s ease;font-size:13px}
.actions button,.ghost-btn{background:rgba(255,255,255,.08);color:var(--text);border:1px solid rgba(255,255,255,.06)}
.actions button:hover,.ghost-btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.12)}
.primary-btn{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06111c;box-shadow:0 18px 34px rgba(95,140,255,.24)}
.danger-btn{background:rgba(255,142,161,.14);color:#ffd8df;border:1px solid rgba(255,142,161,.2)}
.layout{display:grid;grid-template-columns:180px 1fr;min-height:700px}
.sidebar{padding:14px;border-right:1px solid var(--line);background:rgba(255,255,255,.02)}
.nav{display:grid;gap:8px}
.nav a{padding:10px 12px;border-radius:12px;border:1px solid transparent;color:#d7e2f2;font-size:13px;font-weight:700}
.nav a:hover{background:rgba(255,255,255,.04);border-color:rgba(95,140,255,.16)}
.nav a.active{background:linear-gradient(135deg, rgba(95,140,255,.16), rgba(115,223,196,.10));border-color:rgba(95,140,255,.25)}
.content{padding:18px;display:grid;gap:14px;align-content:start}
.section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px}
.section-head h2{font-size:22px;letter-spacing:-.04em}
.section-toolbar{display:flex;gap:10px;flex-wrap:wrap}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px}
.card{padding:22px;border-radius:24px;border:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015))}
.card h3{margin-bottom:10px;font-size:18px}
.card p{font-size:14px}
.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-8{grid-column:span 8}.span-12{grid-column:span 12}
.metric{display:grid;gap:8px}.metric strong{font-size:34px;letter-spacing:-.05em}.metric span{color:var(--muted);font-size:13px}
.list{display:grid;gap:10px;margin-top:14px}
.list-item{padding:14px 16px;border-radius:16px;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02);display:flex;justify-content:space-between;gap:12px;align-items:center}
.list-item small{color:var(--muted)}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.form-grid .full{grid-column:1 / -1}
label{display:grid;gap:8px;color:var(--muted);font-size:13px;font-weight:700}
input,select,textarea{width:100%;min-height:46px;padding:12px 14px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);outline:none}
textarea{min-height:110px;resize:vertical}
input:focus,select:focus,textarea:focus{border-color:rgba(95,140,255,.28);box-shadow:0 0 0 4px rgba(95,140,255,.12)}
.checkbox{display:flex;align-items:center;gap:10px;color:var(--text)}
.checkbox input{width:18px;min-height:auto;height:18px}
.notice{padding:13px 14px;border-radius:16px;border:1px solid rgba(115,223,196,.2);background:rgba(115,223,196,.10);color:#c6fff0;font-size:13px}
.warning{border-color:rgba(255,207,125,.22);background:rgba(255,207,125,.10);color:#ffe7b6}
.mono{font-family:Consolas,monospace}
.editor-shell{display:grid;gap:16px}
.editor-row{padding:18px;border-radius:20px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.025);display:grid;gap:14px}
.editor-row header{padding:0;border:none;background:none;display:flex;justify-content:space-between;align-items:center;gap:14px}
.editor-row header strong{font-size:16px}
.editor-row header small{color:var(--muted);display:block;margin-top:4px}
.editor-actions{display:flex;gap:8px;align-items:center}
.inline-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.inline-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.inline-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.editor-note{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.08);color:var(--muted);font-size:12px}
.status-bar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.status-pill{padding:8px 10px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);color:var(--muted);font-size:12px}
.footer-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px}
.slide-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:18px}
.canvas-shell{display:grid;gap:12px}
.canvas-stage{position:relative;overflow:hidden;aspect-ratio:16/9;width:100%;border-radius:24px;border:1px solid rgba(255,255,255,.08);background:#1a1a2e;box-shadow:inset 0 1px 0 rgba(255,255,255,.05)}
.canvas-stage::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(0,0,0,.08), rgba(0,0,0,.18));pointer-events:none}
.canvas-block{position:absolute;overflow:hidden;z-index:1}
.canvas-block.text,.canvas-block.clock{display:flex;white-space:pre-wrap;line-height:1.1;font-weight:800;text-shadow:0 4px 18px rgba(0,0,0,.24)}
.canvas-block.text{align-items:flex-start}
.canvas-block.clock{align-items:center;justify-content:center}
.canvas-block.image img{width:100%;height:100%;object-fit:cover;border-radius:18px;border:1px solid rgba(255,255,255,.12)}
.block-list{display:grid;gap:12px}
.block-card{padding:14px;border-radius:18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);display:grid;gap:12px}
.block-card header{padding:0;border:none;background:none;display:flex;justify-content:space-between;align-items:center;gap:12px}
.block-card header strong{font-size:15px}
.block-card header small{color:var(--muted)}
.block-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.block-actions{display:flex;gap:8px;flex-wrap:wrap}
.slide-studio{display:grid;grid-template-columns:180px minmax(0,1fr) 220px;gap:12px}
.studio-column{display:grid;gap:14px;align-content:start}
.studio-panel{padding:12px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03)}
.studio-panel h4{font-size:14px;margin-bottom:8px}
.toolbox{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.tool-card{padding:9px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:linear-gradient(135deg, rgba(95,140,255,.14), rgba(115,223,196,.06));cursor:grab;text-align:left;color:var(--text);min-height:auto}
.tool-card strong{display:block;font-size:13px}
.tool-card small{display:block;margin-top:2px;color:var(--muted);font-size:11px}
.tool-card[data-tool="text"]{background:linear-gradient(135deg, rgba(95,140,255,.18), rgba(95,140,255,.05))}
.tool-card[data-tool="clock"]{background:linear-gradient(135deg, rgba(115,223,196,.18), rgba(115,223,196,.05))}
.tool-card[data-tool="image"]{background:linear-gradient(135deg, rgba(255,207,125,.18), rgba(255,207,125,.05))}
.slide-list{display:grid;gap:10px}
.slide-item-btn,.block-pill{width:100%;padding:10px 11px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);color:var(--text);text-align:left;cursor:pointer}
.slide-item-btn.active,.block-pill.active{border-color:rgba(95,140,255,.26);background:linear-gradient(135deg, rgba(95,140,255,.18), rgba(115,223,196,.08))}
.slide-item-btn strong,.block-pill strong{display:block;font-size:13px}
.slide-item-btn small,.block-pill small{display:block;color:var(--muted);margin-top:4px;font-size:11px}
.studio-main{display:grid;gap:14px}
.studio-topbar{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.studio-canvas{position:relative;overflow:hidden;aspect-ratio:16/9;width:100%;border-radius:22px;border:1px solid rgba(255,255,255,.08);background:#1a1a2e;box-shadow:inset 0 1px 0 rgba(255,255,255,.05)}
.studio-canvas::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(0,0,0,.06), rgba(0,0,0,.18));pointer-events:none}
.studio-canvas.drag-over{border-color:rgba(115,223,196,.55);box-shadow:0 0 0 4px rgba(115,223,196,.14), inset 0 1px 0 rgba(255,255,255,.05)}
.studio-block{position:absolute;overflow:hidden;z-index:1;border:1px solid transparent;border-radius:18px;cursor:pointer;transition:border-color .16s ease, box-shadow .16s ease;background:transparent;appearance:none;-webkit-appearance:none;padding:0;margin:0;outline:none}
.studio-block.active{border-color:rgba(115,223,196,.62);box-shadow:0 0 0 2px rgba(115,223,196,.18)}
.studio-block.text,.studio-block.clock{display:flex;white-space:pre-wrap;line-height:1.1;font-weight:800;text-shadow:0 4px 18px rgba(0,0,0,.24);min-width:0}
.studio-block.text{align-items:flex-start}
.studio-block.clock{align-items:center;justify-content:center}
.studio-block.text{overflow-wrap:anywhere;word-break:break-word}
.studio-block-inner{width:100%;height:100%}
.studio-block.text .studio-block-inner,.studio-block.clock .studio-block-inner{width:100%;height:100%;transform-origin:top left}
.studio-block.text .studio-block-inner{overflow-wrap:anywhere;word-break:break-word}
.studio-block.clock .studio-block-inner{display:flex;align-items:center;justify-content:center;padding:8px 18px;line-height:1}
.studio-block.image img{width:100%;height:100%;object-fit:cover;border-radius:18px;border:1px solid rgba(255,255,255,.12)}
.block-overlay-menu{position:absolute;top:8px;right:8px;display:flex;gap:6px;z-index:4}
.block-overlay-menu button{min-height:auto;min-width:28px;padding:5px 7px;border-radius:9px;border:1px solid rgba(255,255,255,.1);background:rgba(8,13,24,.78);color:#e7eefc;font-size:12px;font-weight:700;cursor:pointer;display:grid;place-items:center}
.block-overlay-menu button:hover{background:rgba(16,24,40,.96)}
.block-overlay-menu .danger{color:#ffd8df;border-color:rgba(255,142,161,.22)}
.resize-handle{position:absolute;width:12px;height:12px;border-radius:999px;border:2px solid rgba(11,18,31,.88);background:linear-gradient(135deg, rgba(95,140,255,.98), rgba(115,223,196,.92));box-shadow:0 6px 18px rgba(0,0,0,.28);z-index:3;opacity:.92}
.resize-handle.se{right:6px;bottom:6px;cursor:nwse-resize}
.snap-line{position:absolute;background:rgba(115,223,196,.55);pointer-events:none;z-index:2;box-shadow:0 0 0 1px rgba(115,223,196,.18)}
.snap-line.v{top:0;bottom:0;width:1px}
.snap-line.h{left:0;right:0;height:1px}
.helper{font-size:12px;color:var(--muted);line-height:1.4}
.stack{display:grid;gap:12px}
.mini-meta{display:flex;gap:8px;flex-wrap:wrap}
.mini-meta span{padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);font-size:12px;color:var(--muted)}
.compact-fields{display:grid;grid-template-columns:1.2fr 1fr .8fr;gap:10px}
.inspector-blocks{display:grid;gap:8px;margin-bottom:12px}
.context-menu{position:fixed;z-index:50;min-width:220px;padding:8px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(11,18,31,.94);box-shadow:0 24px 60px rgba(0,0,0,.36);display:none}
.context-menu button{width:100%;padding:11px 12px;border:none;background:transparent;color:var(--text);text-align:left;border-radius:12px;cursor:pointer}
.context-menu button:hover{background:rgba(255,255,255,.08)}
.context-menu hr{border:none;border-top:1px solid rgba(255,255,255,.08);margin:6px 0}
.editor-workspace{display:grid;grid-template-columns:190px minmax(0,1fr);gap:0;border:1px solid rgba(255,255,255,.06);border-radius:22px;overflow:hidden;background:rgba(255,255,255,.02)}
.editor-sidebar{padding:0;border-right:1px solid rgba(255,255,255,.06);background:rgba(12,18,32,.78);display:grid;grid-template-rows:auto 1fr auto auto}
.editor-side-section{padding:12px;border-bottom:1px solid rgba(255,255,255,.06)}
.editor-side-section h4{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#7f8ead;margin:0 0 10px}
.editor-side-list{display:grid;gap:8px}
.editor-add-slide{margin:12px}
.editor-main{display:grid;grid-template-rows:auto minmax(0,1fr) auto;background:rgba(17,24,40,.42)}
.editor-toolbar{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06);display:grid;gap:10px;background:rgba(19,26,43,.72)}
.editor-toolbar-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.editor-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.editor-tool-btn{padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.03);color:var(--text);font-size:12px;font-weight:700;cursor:pointer}
.editor-tool-btn:hover{background:rgba(255,255,255,.07)}
.editor-tool-btn.text{border-color:rgba(95,140,255,.22)}
.editor-tool-btn.clock{border-color:rgba(115,223,196,.22)}
.editor-tool-btn.image{border-color:rgba(255,207,125,.22)}
.editor-toolbar-group{display:flex;gap:8px;align-items:center;padding-left:10px;border-left:1px solid rgba(255,255,255,.08)}
.editor-toolbar-group label{display:flex;gap:6px;align-items:center;font-size:12px;color:var(--muted);font-weight:600}
.editor-toolbar-group input,.editor-toolbar-group select{min-height:34px;padding:7px 10px;border-radius:10px;font-size:12px}
.editor-stage-wrap{position:relative;min-height:720px;padding:16px;background-image:linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px),linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);background-size:40px 40px;background-position:center}
.editor-stage-inner{position:absolute;inset:16px;overflow:auto}
.editor-stage-shell{width:100%;height:100%;display:grid;place-items:center}
.editor-canvas-shell{width:min(1100px, 100%);aspect-ratio:16/9;position:relative}
.editor-bottombar{padding:10px 16px;border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:rgba(19,26,43,.72)}
.editor-quiet{font-size:12px;color:var(--muted)}
.modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(2,7,14,.72);backdrop-filter:blur(10px);z-index:80}
.modal-backdrop.open{display:flex}
.modal-card{width:min(720px,100%);padding:22px;border-radius:24px;border:1px solid rgba(255,255,255,.10);background:linear-gradient(180deg, rgba(20,31,51,.96), rgba(12,20,34,.98));box-shadow:0 36px 80px rgba(0,0,0,.45);display:grid;gap:16px}
.modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.modal-head h4{font-size:22px;letter-spacing:-.03em}
.modal-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
code{font-family:Consolas,monospace;background:rgba(255,255,255,.05);padding:2px 6px;border-radius:7px}
@media (max-width:1100px){.layout{grid-template-columns:1fr}.sidebar{border-right:none;border-bottom:1px solid var(--line)}.span-3,.span-4,.span-8,.span-12{grid-column:span 12}.inline-grid,.inline-grid.two,.inline-grid.three,.form-grid,.slide-layout,.block-grid,.slide-studio,.compact-fields{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
  <header>
    <div>
      <div class="badge"><?= htmlspecialchars($version['label'] ?? 'v3.0.0') ?></div>
      <h1>Schauboard v3</h1>
      <p>Editor-zentriertes v3-Backend mit weniger Menues und direkterer Bearbeitung auf der Folie.</p>
    </div>
    <div class="actions">
      <a class="ghost-btn" href="../?display=default" target="_blank" rel="noreferrer">Display ansehen</a>
      <form method="post">
        <button type="submit" name="logout" value="1">Abmelden</button>
      </form>
    </div>
  </header>
  <div class="layout">
    <aside class="sidebar">
      <nav class="nav">
        <?php foreach ($sectionTitles as $key => $label): ?>
          <a href="?section=<?= urlencode($key) ?>" class="<?= $section === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </aside>
    <main class="content">
      <div class="section-head">
        <div>
          <h2><?= htmlspecialchars($sectionTitles[$section] ?? 'Dashboard') ?></h2>
          <?php if ($section !== 'editor'): ?>
          <p><?= htmlspecialchars(match ($section) {
              'editor' => 'Folie auswaehlen, Block auf die Buehne legen und direkt auf der Flaeche bearbeiten.',
              'settings' => 'Globale Systemeinstellungen, Branding und Wartungsstatus.',
              'displays' => 'Nur die Displays selbst: Name, URL und was standardmaessig gezeigt wird.',
              default => 'Verwaltung',
          }) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($section !== 'editor'): ?>
          <div class="section-toolbar">
            <?php if ($section === 'displays'): ?><button type="button" class="ghost-btn" id="addDisplay">Display hinzufuegen</button><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($section === 'settings'): ?>
        <section class="card span-12">
          <div class="status-bar" style="margin-bottom:16px;">
            <h3>Globale Einstellungen</h3>
            <div id="settingsStatus" class="status-pill">Noch nicht gespeichert</div>
          </div>
          <form id="settingsForm" class="form-grid">
            <label>
              Produktname
              <input type="text" name="branding_name" value="<?= htmlspecialchars($settings['branding']['name'] ?? 'Schauboard') ?>">
            </label>
            <label>
              Zeitzone
              <input type="text" name="timezone" value="<?= htmlspecialchars($settings['system']['timezone'] ?? 'Europe/Zurich') ?>">
            </label>
            <label>
              Sprache
              <input type="text" name="language" value="<?= htmlspecialchars($settings['system']['language'] ?? 'de') ?>">
            </label>
            <label>
              Standard-Slide-Dauer
              <input type="number" min="3" max="120" name="default_slide_duration" value="<?= (int) ($settings['system']['default_slide_duration'] ?? 10) ?>">
            </label>
            <label>
              Standard-Transition
              <select name="default_transition">
                <?php foreach (['fade', 'slide-left', 'slide-up', 'zoom', 'none'] as $transition): ?>
                  <option value="<?= $transition ?>" <?= (($settings['system']['default_transition'] ?? 'fade') === $transition) ? 'selected' : '' ?>><?= htmlspecialchars($transition) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Wetter-Ort
              <input type="text" name="weather_location" value="<?= htmlspecialchars($settings['weather']['location'] ?? 'Zurich,CH') ?>">
            </label>
            <label>
              Wetter-Provider
              <input type="text" name="weather_provider" value="<?= htmlspecialchars($settings['weather']['provider'] ?? 'wttr.in') ?>">
            </label>
            <label class="checkbox full">
              <input type="checkbox" name="weather_enabled" <?= !empty($settings['weather']['enabled']) ? 'checked' : '' ?>>
              Wetter aktivieren
            </label>
            <label class="checkbox full">
              <input type="checkbox" name="maintenance_enabled" <?= !empty($settings['maintenance']['enabled']) ? 'checked' : '' ?>>
              Wartungsmodus aktivieren
            </label>
            <label class="full">
              Wartungsmeldung
              <textarea name="maintenance_message"><?= htmlspecialchars($settings['maintenance']['message'] ?? '') ?></textarea>
            </label>
            <div class="full footer-actions">
              <div class="status-pill">Speichert direkt nach <code>data/settings.json</code></div>
              <button type="submit" class="primary-btn">Einstellungen speichern</button>
            </div>
          </form>
        </section>
      <?php elseif ($section === 'displays'): ?>
        <section class="card span-12">
          <div class="status-bar" style="margin-bottom:16px;">
            <h3>Displays</h3>
            <div id="displaysStatus" class="status-pill">Noch nicht gespeichert</div>
          </div>
          <div id="displaysEditor" class="editor-shell">
            <?php foreach ($displays as $display): ?>
              <article class="editor-row" data-item="display">
                <header>
                  <div>
                    <strong><?= htmlspecialchars($display['name'] ?? 'Display') ?></strong>
                    <small>URL: <code>/?display=<?= htmlspecialchars($display['id'] ?? '') ?></code></small>
                  </div>
                  <div class="editor-actions">
                    <button type="button" class="danger-btn" data-remove>Entfernen</button>
                  </div>
                </header>
                <div class="inline-grid three">
                  <label>Name<input type="text" data-field="name" value="<?= htmlspecialchars($display['name'] ?? '') ?>"></label>
                  <label>ID<input type="text" data-field="id" value="<?= htmlspecialchars($display['id'] ?? '') ?>"></label>
                  <label>Standard-Playlist<input type="text" data-field="default_playlist_id" value="<?= htmlspecialchars($display['default_playlist_id'] ?? '') ?>"></label>
                </div>
                <div class="inline-grid two">
                  <label>Token<input type="text" data-field="token" value="<?= htmlspecialchars($display['token'] ?? '') ?>"></label>
                  <label>Letzter Heartbeat<input type="text" data-field="last_seen_at" value="<?= htmlspecialchars((string) ($display['last_seen_at'] ?? '')) ?>" placeholder="z. B. 2026-04-21 12:30"></label>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="footer-actions">
            <div class="status-pill">Jedes Display bekommt spaeter eigene Diagnose- und Heartbeat-Daten.</div>
            <button type="button" class="primary-btn" id="saveDisplays">Displays speichern</button>
          </div>
        </section>
      <?php elseif ($section === 'playlists'): ?>
        <section class="card span-12">
          <div class="status-bar" style="margin-bottom:16px;">
            <h3>Playlists</h3>
            <div id="playlistsStatus" class="status-pill">Noch nicht gespeichert</div>
          </div>
          <div id="playlistsEditor" class="editor-shell">
            <?php foreach ($playlists as $playlist): ?>
              <article class="editor-row" data-item="playlist">
                <header>
                  <div>
                    <strong><?= htmlspecialchars($playlist['name'] ?? 'Playlist') ?></strong>
                    <small><?= count($playlist['slide_ids'] ?? []) ?> Slide-Referenzen</small>
                  </div>
                  <div class="editor-actions">
                    <button type="button" class="danger-btn" data-remove>Entfernen</button>
                  </div>
                </header>
                <div class="inline-grid three">
                  <label>Name<input type="text" data-field="name" value="<?= htmlspecialchars($playlist['name'] ?? '') ?>"></label>
                  <label>ID<input type="text" data-field="id" value="<?= htmlspecialchars($playlist['id'] ?? '') ?>"></label>
                  <label>Slide-IDs (Komma getrennt)<input type="text" data-field="slide_ids" value="<?= htmlspecialchars(implode(', ', $playlist['slide_ids'] ?? [])) ?>"></label>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="footer-actions">
            <div class="status-pill">Playlists referenzieren Slides nur per ID und bleiben damit leicht migrierbar.</div>
            <button type="button" class="primary-btn" id="savePlaylists">Playlists speichern</button>
          </div>
        </section>
      <?php elseif ($section === 'schedules'): ?>
        <section class="card span-12">
          <div class="status-bar" style="margin-bottom:16px;">
            <h3>Zeitfenster</h3>
            <div id="schedulesStatus" class="status-pill">Noch nicht gespeichert</div>
          </div>
          <?php if ($schedules === []): ?>
            <div class="notice warning" style="margin-bottom:16px;">Noch keine Zeitfenster definiert. Das ist okay fuer den ersten Aufbau.</div>
          <?php endif; ?>
          <div id="schedulesEditor" class="editor-shell">
            <?php foreach ($schedules as $schedule): ?>
              <article class="editor-row" data-item="schedule">
                <header>
                  <div>
                    <strong><?= htmlspecialchars($schedule['name'] ?? 'Zeitfenster') ?></strong>
                    <small><?= htmlspecialchars(($schedule['from'] ?? '00:00') . ' - ' . ($schedule['to'] ?? '23:59')) ?></small>
                  </div>
                  <div class="editor-actions">
                    <button type="button" class="danger-btn" data-remove>Entfernen</button>
                  </div>
                </header>
                <div class="inline-grid three">
                  <label>Name<input type="text" data-field="name" value="<?= htmlspecialchars($schedule['name'] ?? '') ?>"></label>
                  <label>ID<input type="text" data-field="id" value="<?= htmlspecialchars($schedule['id'] ?? '') ?>"></label>
                  <label>Display-ID<input type="text" data-field="display_id" value="<?= htmlspecialchars($schedule['display_id'] ?? '') ?>"></label>
                </div>
                <div class="inline-grid three">
                  <label>Playlist-ID<input type="text" data-field="playlist_id" value="<?= htmlspecialchars($schedule['playlist_id'] ?? '') ?>"></label>
                  <label>Von<input type="time" data-field="from" value="<?= htmlspecialchars($schedule['from'] ?? '00:00') ?>"></label>
                  <label>Bis<input type="time" data-field="to" value="<?= htmlspecialchars($schedule['to'] ?? '23:59') ?>"></label>
                </div>
                <label>Tage (Komma getrennt, z. B. mon,tue,wed)<input type="text" data-field="days" value="<?= htmlspecialchars(implode(',', $schedule['days'] ?? [])) ?>"></label>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="footer-actions">
            <div class="status-pill">Tage nutzen die Schluessel <code>mon,tue,wed,thu,fri,sat,sun</code>.</div>
            <button type="button" class="primary-btn" id="saveSchedules">Zeitfenster speichern</button>
          </div>
        </section>
      <?php else: ?>
        <section class="card span-12">
          <div class="editor-workspace">
            <aside class="editor-sidebar">
              <section class="editor-side-section">
                <h4>Folien</h4>
                <div id="slidesList" class="editor-side-list"></div>
              </section>
              <div></div>
              <div class="editor-add-slide">
                <button type="button" class="ghost-btn" id="addSlide" style="width:100%;">+ Folie hinzufügen</button>
              </div>
              <section class="editor-side-section">
                <h4>Ebenen</h4>
                <div id="studioBlockList" class="editor-side-list"></div>
              </section>
            </aside>

            <section class="editor-main">
              <div class="editor-toolbar">
                <div class="editor-toolbar-row">
                  <div class="editor-tools">
                    <button type="button" class="editor-tool-btn text" id="addTextBlock" data-tool="text" draggable="true">Text</button>
                    <button type="button" class="editor-tool-btn clock" id="addClockBlock" data-tool="clock" draggable="true">Uhr</button>
                    <button type="button" class="editor-tool-btn image" id="addImageBlock" data-tool="image" draggable="true">Bild</button>
                  </div>
                  <div class="editor-toolbar-group">
                    <label>Name <input type="text" id="studioSlideName"></label>
                    <label>Dauer <input type="number" min="3" max="300" id="studioSlideDuration"></label>
                  </div>
                  <div class="editor-toolbar-group">
                    <button type="button" class="ghost-btn" id="assignDisplayButton">Display zuordnen</button>
                  </div>
                  <div class="editor-toolbar-group">
                    <div id="selectedSlideMeta" class="editor-quiet">Noch keine Folie ausgewählt.</div>
                  </div>
                </div>
                <div class="editor-toolbar-row">
                  <details>
                    <summary class="helper" style="cursor:pointer;">Folie anpassen</summary>
                    <div class="editor-toolbar-group" style="margin-top:8px;border-left:none;padding-left:0;">
                      <label>ID <input type="text" id="studioSlideId"></label>
                      <label>Farbe <input type="text" id="studioSlideBgColor"></label>
                      <label>Bild <input type="text" id="studioSlideBgImage" placeholder="optional"></label>
                    </div>
                  </details>
                </div>
              </div>

              <div class="editor-stage-wrap">
                <div class="editor-stage-inner">
                  <div class="editor-stage-shell">
                    <div class="editor-canvas-shell">
                      <div id="studioCanvas" class="studio-canvas"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="editor-bottombar">
                <div class="editor-quiet" id="slidesStatus">Noch nicht gespeichert</div>
                <button type="button" class="primary-btn" id="saveSlides">Speichern</button>
              </div>
            </section>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<div id="canvasContextMenu" class="context-menu">
  <button type="button" data-context-action="add-text">Text hier einfuegen</button>
  <button type="button" data-context-action="add-clock">Uhr hier einfuegen</button>
  <button type="button" data-context-action="add-image">Bild hier einfuegen</button>
  <hr>
  <button type="button" data-context-action="assign-display">Dieser Folie ein Display zuordnen</button>
</div>

<div id="blockEditModal" class="modal-backdrop">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h4>Block bearbeiten</h4>
        <div id="blockEditMeta" class="helper">Text-Block</div>
      </div>
      <button type="button" class="ghost-btn" id="closeBlockModal">Schliessen</button>
    </div>
    <div id="blockEditForm" class="form-grid">
      <label>Typ
        <select id="modalType">
          <option value="text">Text</option>
          <option value="clock">Uhr</option>
          <option value="image">Bild</option>
        </select>
      </label>
      <label id="modalFontSizeField">Schriftgroesse<input type="number" min="16" max="160" id="modalFontSize"></label>
      <label id="modalTextField" class="full">Inhalt<textarea id="modalText"></textarea></label>
      <label id="modalSrcField" class="full">Bild-URL / Pfad<input type="text" id="modalSrc"></label>
      <label id="modalColorField">Farbe<input type="text" id="modalColor"></label>
      <label id="modalAlignField">Ausrichtung
        <select id="modalAlign">
          <option value="left">Links</option>
          <option value="center">Mitte</option>
          <option value="right">Rechts</option>
        </select>
      </label>
      <details class="full">
        <summary class="helper" style="cursor:pointer;">Erweitert</summary>
        <div class="inline-grid two" style="margin-top:12px;">
          <label>Block-ID<input type="text" id="modalId"></label>
          <label>X<input type="number" min="0" max="1720" id="modalX"></label>
          <label>Y<input type="number" min="0" max="980" id="modalY"></label>
          <label>Breite<input type="number" min="120" max="1920" id="modalW"></label>
          <label>Hoehe<input type="number" min="60" max="1080" id="modalH"></label>
        </div>
      </details>
    </div>
    <div class="modal-actions">
      <button type="button" class="danger-btn" id="deleteBlockFromModal">Block entfernen</button>
      <button type="button" class="primary-btn" id="saveBlockModal">Uebernehmen</button>
    </div>
  </div>
</div>

<template id="displayTemplate">
  <article class="editor-row" data-item="display">
    <header>
      <div>
        <strong>Neues Display</strong>
        <small>URL: <code>/?display=neues-display</code></small>
      </div>
      <div class="editor-actions"><button type="button" class="danger-btn" data-remove>Entfernen</button></div>
    </header>
    <div class="inline-grid three">
      <label>Name<input type="text" data-field="name" value="Neues Display"></label>
      <label>ID<input type="text" data-field="id" value="display_neu"></label>
      <label>Standard-Playlist<input type="text" data-field="default_playlist_id" value="playlist_default"></label>
    </div>
    <div class="inline-grid two">
      <label>Token<input type="text" data-field="token" value=""></label>
      <label>Letzter Heartbeat<input type="text" data-field="last_seen_at" value="" placeholder="z. B. 2026-04-21 12:30"></label>
    </div>
  </article>
</template>

<template id="playlistTemplate">
  <article class="editor-row" data-item="playlist">
    <header>
      <div>
        <strong>Neue Playlist</strong>
        <small>0 Slide-Referenzen</small>
      </div>
      <div class="editor-actions"><button type="button" class="danger-btn" data-remove>Entfernen</button></div>
    </header>
    <div class="inline-grid three">
      <label>Name<input type="text" data-field="name" value="Neue Playlist"></label>
      <label>ID<input type="text" data-field="id" value="playlist_neu"></label>
      <label>Slide-IDs (Komma getrennt)<input type="text" data-field="slide_ids" value="slide_welcome"></label>
    </div>
  </article>
</template>

<template id="scheduleTemplate">
  <article class="editor-row" data-item="schedule">
    <header>
      <div>
        <strong>Neues Zeitfenster</strong>
        <small>00:00 - 23:59</small>
      </div>
      <div class="editor-actions"><button type="button" class="danger-btn" data-remove>Entfernen</button></div>
    </header>
    <div class="inline-grid three">
      <label>Name<input type="text" data-field="name" value="Neues Zeitfenster"></label>
      <label>ID<input type="text" data-field="id" value="schedule_neu"></label>
      <label>Display-ID<input type="text" data-field="display_id" value="default"></label>
    </div>
    <div class="inline-grid three">
      <label>Playlist-ID<input type="text" data-field="playlist_id" value="playlist_default"></label>
      <label>Von<input type="time" data-field="from" value="00:00"></label>
      <label>Bis<input type="time" data-field="to" value="23:59"></label>
    </div>
    <label>Tage (Komma getrennt, z. B. mon,tue,wed)<input type="text" data-field="days" value="mon,tue,wed,thu,fri"></label>
  </article>
</template>

<template id="slideTemplate">
  <article class="editor-row" data-item="slide">
    <header>
      <div>
        <strong>Neue Slide</strong>
        <small>10 Sekunden</small>
      </div>
      <div class="editor-actions"><button type="button" class="danger-btn" data-remove>Entfernen</button></div>
    </header>
    <div class="slide-layout">
      <div class="canvas-shell">
        <div class="inline-grid three">
          <label>Name<input type="text" data-field="name" value="Neue Slide"></label>
          <label>ID<input type="text" data-field="id" value="slide_neu"></label>
          <label>Dauer<input type="number" min="3" max="300" data-field="duration" value="10"></label>
        </div>
        <div class="inline-grid two">
          <label>Hintergrundfarbe<input type="text" data-field="bg_color" value="#1a1a2e"></label>
          <label>Hintergrundbild<input type="text" data-field="bg_image" value=""></label>
        </div>
        <div class="canvas-stage" data-canvas></div>
        <div class="editor-note">Starte mit einem leeren Canvas und fuege die ersten Blocks hinzu.</div>
      </div>
      <div class="block-list" data-blocks></div>
    </div>
    <div class="block-actions">
      <button type="button" class="ghost-btn" data-add-block="text">Text-Block</button>
      <button type="button" class="ghost-btn" data-add-block="clock">Uhr-Block</button>
      <button type="button" class="ghost-btn" data-add-block="image">Bild-Block</button>
    </div>
  </article>
</template>

<template id="blockTemplate">
  <section class="block-card" data-block>
    <header>
      <div>
        <strong>Text-Block</strong>
        <small>block_neu</small>
      </div>
      <button type="button" class="danger-btn" data-remove-block>Block entfernen</button>
    </header>
    <div class="block-grid">
      <label>Typ
        <select data-block-field="type">
          <option value="text" selected>Text</option>
          <option value="clock">Uhr</option>
          <option value="image">Bild</option>
        </select>
      </label>
      <label>Block-ID<input type="text" data-block-field="id" value="block_neu"></label>
      <label>X<input type="number" min="0" max="1720" data-block-field="x" value="120"></label>
      <label>Y<input type="number" min="0" max="980" data-block-field="y" value="120"></label>
      <label>Breite<input type="number" min="120" max="1920" data-block-field="w" value="420"></label>
      <label>Hoehe<input type="number" min="60" max="1080" data-block-field="h" value="140"></label>
      <label>Text<textarea data-block-field="text">Neuer Text</textarea></label>
      <label>Bild-URL / Pfad<input type="text" data-block-field="src" value=""></label>
      <label>Schriftgroesse<input type="number" min="16" max="160" data-block-field="font_size" value="42"></label>
      <label>Farbe<input type="text" data-block-field="color" value="#ffffff"></label>
      <label>Ausrichtung
        <select data-block-field="align">
          <option value="left" selected>Links</option>
          <option value="center">Mitte</option>
          <option value="right">Rechts</option>
        </select>
      </label>
    </div>
  </section>
</template>

<script>
const appState = <?= json_encode($jsState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const studioState = {
  slides: Array.isArray(appState.slides) ? JSON.parse(JSON.stringify(appState.slides)) : [],
  playlists: Array.isArray(appState.playlists) ? JSON.parse(JSON.stringify(appState.playlists)) : [],
  displays: Array.isArray(appState.displays) ? JSON.parse(JSON.stringify(appState.displays)) : [],
  selectedSlideId: null,
  selectedBlockId: null,
  contextPosition: {x: 120, y: 120},
  dragToolType: null,
  dragBlockId: null,
  dragOffset: {x: 0, y: 0},
  modalBlockId: null,
  pointerDownBlockId: null,
  pointerStart: null,
  isDraggingBlock: false,
  resizeBlockId: null,
  resizeHandle: null,
  resizeStartRect: null,
  snapGuides: {x: [], y: []},
  lastClickBlockId: null,
  lastClickAt: 0,
};

function setStatus(id, text, kind = 'idle') {
  const node = document.getElementById(id);
  if (!node) return;
  node.textContent = text;
  node.style.color = kind === 'error' ? '#ffd8df' : kind === 'success' ? '#c6fff0' : '';
  node.style.borderColor = kind === 'error' ? 'rgba(255,142,161,.22)' : kind === 'success' ? 'rgba(115,223,196,.22)' : 'rgba(255,255,255,.06)';
  node.style.background = kind === 'error' ? 'rgba(255,142,161,.12)' : kind === 'success' ? 'rgba(115,223,196,.12)' : 'rgba(255,255,255,.04)';
}

async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload),
  });

  let data = null;
  try {
    data = await response.json();
  } catch (error) {
    throw new Error('Unerwartete Server-Antwort.');
  }

  if (!response.ok || !data || data.ok !== true) {
    throw new Error(data && data.error ? data.error : 'Speichern fehlgeschlagen.');
  }

  return data;
}

function bindRemoveButtons(root = document) {
  root.querySelectorAll('[data-remove]').forEach((button) => {
    if (button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', () => {
      const row = button.closest('.editor-row');
      if (row) row.remove();
    });
  });

  root.querySelectorAll('[data-remove-block]').forEach((button) => {
    if (button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', () => {
      const block = button.closest('[data-block]');
      const slideRow = button.closest('[data-item="slide"]');
      if (block) {
        block.remove();
      }
      if (slideRow) {
        renderSlideCanvas(slideRow);
      }
    });
  });
}

function appendTemplate(buttonId, templateId, targetId) {
  const trigger = document.getElementById(buttonId);
  const template = document.getElementById(templateId);
  const target = document.getElementById(targetId);
  if (!trigger || !template || !target) return;
  trigger.addEventListener('click', () => {
    target.appendChild(template.content.cloneNode(true));
    bindRemoveButtons(target);
  });
}

function serializeRows(targetId, serializer) {
  const target = document.getElementById(targetId);
  if (!target) return [];
  return Array.from(target.querySelectorAll('.editor-row')).map((row) => serializer(row));
}

function fieldValue(row, name) {
  const input = row.querySelector(`[data-field="${name}"]`);
  return input ? input.value.trim() : '';
}

function splitCsv(value) {
  return value.split(',').map((item) => item.trim()).filter(Boolean);
}

bindRemoveButtons();
appendTemplate('addDisplay', 'displayTemplate', 'displaysEditor');
appendTemplate('addPlaylist', 'playlistTemplate', 'playlistsEditor');
appendTemplate('addSchedule', 'scheduleTemplate', 'schedulesEditor');
appendTemplate('addSlide', 'slideTemplate', 'slidesEditor');
bindSlidesStudio();

function createStudioBlock(type = 'text') {
  const id = `block_${Math.random().toString(36).slice(2, 8)}`;
  const width = type === 'image' ? 520 : type === 'clock' ? 320 : 420;
  const height = type === 'image' ? 320 : type === 'clock' ? 120 : 140;
  return {
    id,
    type,
    x: 120,
    y: 120,
    w: width,
    h: height,
    base_w: width,
    base_h: height,
    text: type === 'text' ? 'Neuer Text' : '',
    src: '',
    font_size: type === 'clock' ? 64 : 42,
    color: '#ffffff',
    align: type === 'clock' ? 'center' : 'left',
  };
}

function createSlide() {
  const id = `slide_${Math.random().toString(36).slice(2, 8)}`;
  return {
    id,
    name: 'Neue Slide',
    bg_color: '#1a1a2e',
    bg_image: '',
    duration: 10,
    blocks: [],
  };
}

function cloneBlock(block) {
  return {
    ...JSON.parse(JSON.stringify(block)),
    id: `block_${Math.random().toString(36).slice(2, 8)}`,
    x: clamp(Number(block.x || 0) + 40, 0, 1920 - Number(block.w || 0)),
    y: clamp(Number(block.y || 0) + 40, 0, 1080 - Number(block.h || 0)),
  };
}

function canvasPointFromEvent(canvas, clientX, clientY) {
  const rect = canvas.getBoundingClientRect();
  const x = Math.round(((clientX - rect.left) / rect.width) * 1920);
  const y = Math.round(((clientY - rect.top) / rect.height) * 1080);
  return {
    x: Math.max(0, Math.min(1920, x)),
    y: Math.max(0, Math.min(1080, y)),
  };
}

function addStudioBlock(type = 'text', position = null) {
  const slide = getSelectedSlide();
  if (!slide) return;
  const block = createStudioBlock(type);
  if (position) {
    block.x = Math.max(0, Math.min(1720, position.x));
    block.y = Math.max(0, Math.min(980, position.y));
  }
  slide.blocks = Array.isArray(slide.blocks) ? slide.blocks : [];
  slide.blocks.push(block);
  studioState.selectedBlockId = block.id;
  renderSlidesStudio();
}

function ensureSingleSlidePlaylist(slide) {
  const playlistId = `playlist_slide_${slide.id}`;
  let playlist = studioState.playlists.find((item) => item.id === playlistId);
  if (!playlist) {
    playlist = {id: playlistId, name: `${slide.name || 'Slide'} Playlist`, slide_ids: [slide.id]};
    studioState.playlists.push(playlist);
  } else {
    playlist.name = `${slide.name || 'Slide'} Playlist`;
    playlist.slide_ids = [slide.id];
  }
  return playlistId;
}

async function assignSelectedSlideToDisplay() {
  const slide = getSelectedSlide();
  if (!slide) return;
  if (!studioState.displays.length) {
    setStatus('slidesStatus', 'Es gibt noch keine Displays fuer die Zuordnung.', 'error');
    return;
  }

  const available = studioState.displays.map((display) => `${display.id} (${display.name})`).join(', ');
  const chosen = window.prompt(`Welches Display soll diese Folie standardmaessig zeigen?\nVerfuegbar: ${available}`, studioState.displays[0].id || 'default');
  if (!chosen) return;
  const match = studioState.displays.find((display) => display.id === chosen.trim());
  if (!match) {
    setStatus('slidesStatus', 'Display nicht gefunden.', 'error');
    return;
  }

  const playlistId = ensureSingleSlidePlaylist(slide);
  match.default_playlist_id = playlistId;

  try {
    await postJson('../api/playlists.php', {items: studioState.playlists});
    await postJson('../api/displays.php', {items: studioState.displays});
    setStatus('slidesStatus', `Folie ${slide.name || slide.id} dem Display ${match.name} zugeordnet`, 'success');
  } catch (error) {
    setStatus('slidesStatus', error.message, 'error');
  }
}

function ensureSlidesStudioSelection() {
  if (!studioState.slides.length) {
    studioState.selectedSlideId = null;
    studioState.selectedBlockId = null;
    return;
  }

  if (!studioState.selectedSlideId || !studioState.slides.some((slide) => slide.id === studioState.selectedSlideId)) {
    studioState.selectedSlideId = studioState.slides[0].id;
  }

  const slide = getSelectedSlide();
  if (!slide || !Array.isArray(slide.blocks) || slide.blocks.length === 0) {
    studioState.selectedBlockId = null;
    return;
  }

  if (!studioState.selectedBlockId || !slide.blocks.some((block) => block.id === studioState.selectedBlockId)) {
    studioState.selectedBlockId = slide.blocks[0].id;
  }
}

function getSelectedSlide() {
  return studioState.slides.find((slide) => slide.id === studioState.selectedSlideId) || null;
}

function getSelectedBlock() {
  const slide = getSelectedSlide();
  if (!slide || !Array.isArray(slide.blocks)) return null;
  return slide.blocks.find((block) => block.id === studioState.selectedBlockId) || null;
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function clearSnapGuides() {
  studioState.snapGuides = {x: [], y: []};
}

function getSnapCandidates(slide, excludeId) {
  const x = [0, 960, 1920];
  const y = [0, 540, 1080];
  (slide.blocks || []).forEach((block) => {
    if (block.id === excludeId) return;
    const bx = Number(block.x || 0);
    const by = Number(block.y || 0);
    const bw = Number(block.w || 0);
    const bh = Number(block.h || 0);
    x.push(bx, bx + (bw / 2), bx + bw);
    y.push(by, by + (bh / 2), by + bh);
  });
  return {x, y};
}

function applySnapToBlock(block, nextX, nextY, candidates, threshold = 14) {
  const width = Number(block.w || 0);
  const height = Number(block.h || 0);
  const xPoints = [
    {value: nextX, offset: 0},
    {value: nextX + (width / 2), offset: width / 2},
    {value: nextX + width, offset: width},
  ];
  const yPoints = [
    {value: nextY, offset: 0},
    {value: nextY + (height / 2), offset: height / 2},
    {value: nextY + height, offset: height},
  ];

  let bestX = {distance: Infinity, snapped: nextX, guide: null};
  xPoints.forEach((point) => {
    candidates.x.forEach((line) => {
      const distance = Math.abs(point.value - line);
      if (distance < threshold && distance < bestX.distance) {
        bestX = {distance, snapped: line - point.offset, guide: line};
      }
    });
  });

  let bestY = {distance: Infinity, snapped: nextY, guide: null};
  yPoints.forEach((point) => {
    candidates.y.forEach((line) => {
      const distance = Math.abs(point.value - line);
      if (distance < threshold && distance < bestY.distance) {
        bestY = {distance, snapped: line - point.offset, guide: line};
      }
    });
  });

  return {
    x: bestX.guide === null ? nextX : bestX.snapped,
    y: bestY.guide === null ? nextY : bestY.snapped,
    guides: {
      x: bestX.guide === null ? [] : [bestX.guide],
      y: bestY.guide === null ? [] : [bestY.guide],
    },
  };
}

function getBlockBaseSize(block) {
  const type = block.type || 'text';
  return {
    width: Math.max(80, Number(block.base_w || (type === 'clock' ? 320 : type === 'image' ? Number(block.w || 520) : 420))),
    height: Math.max(48, Number(block.base_h || (type === 'clock' ? 120 : type === 'image' ? Number(block.h || 320) : 140))),
  };
}

function getBlockScale(block) {
  const type = block.type || 'text';
  if (type === 'image') return 1;
  if (type === 'clock') return 1;
  const base = getBlockBaseSize(block);
  const width = Math.max(40, Number(block.w || base.width));
  const height = Math.max(24, Number(block.h || base.height));
  const scale = Math.min(width / base.width, height / base.height);
  return Math.max(0.3, scale);
}

function getClockFontSize(block) {
  const width = Math.max(80, Number(block.w || 320));
  const height = Math.max(48, Number(block.h || 120));
  const baseFont = Math.max(16, Number(block.font_size) || 64);
  const scaled = Math.max(16, Math.round(baseFont * Math.min(width / 320, height / 120)));
  return Math.max(16, Math.min(scaled, Math.round(height * 0.72), Math.round(width / 3.1)));
}

function renderSlidesList() {
  const list = document.getElementById('slidesList');
  if (!list) return;
  list.innerHTML = '';

  if (!studioState.slides.length) {
    list.innerHTML = '<div class="helper">Noch keine Folien vorhanden.</div>';
    return;
  }

  studioState.slides.forEach((slide) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `slide-item-btn${slide.id === studioState.selectedSlideId ? ' active' : ''}`;
    button.innerHTML = `<strong>${escapeHtml(slide.name || 'Slide')}</strong><small>${(slide.blocks || []).length} Blocks · ${Number(slide.duration || 10)}s</small>`;
    button.addEventListener('click', () => {
      studioState.selectedSlideId = slide.id;
      studioState.selectedBlockId = slide.blocks && slide.blocks[0] ? slide.blocks[0].id : null;
      renderSlidesStudio();
    });
    list.appendChild(button);
  });
}

function renderSlideMeta() {
  const meta = document.getElementById('selectedSlideMeta');
  const slide = getSelectedSlide();
  if (!meta) return;
  if (!slide) {
    meta.textContent = 'Noch keine Folie ausgewählt.';
    return;
  }
  meta.textContent = `${slide.name || 'Slide'} · ${(slide.blocks || []).length} Blocks · ${Number(slide.duration || 10)}s`;
}

function renderSlideFields() {
  const slide = getSelectedSlide();
  const fields = {
    name: document.getElementById('studioSlideName'),
    id: document.getElementById('studioSlideId'),
    duration: document.getElementById('studioSlideDuration'),
    bgColor: document.getElementById('studioSlideBgColor'),
    bgImage: document.getElementById('studioSlideBgImage'),
  };
  if (!slide) {
    Object.values(fields).forEach((input) => {
      if (input) input.value = '';
    });
    return;
  }
  if (fields.name) fields.name.value = slide.name || '';
  if (fields.id) fields.id.value = slide.id || '';
  if (fields.duration) fields.duration.value = Number(slide.duration || 10);
  if (fields.bgColor) fields.bgColor.value = slide.bg_color || '#1a1a2e';
  if (fields.bgImage) fields.bgImage.value = slide.bg_image || '';
}

function renderStudioCanvas() {
  const canvas = document.getElementById('studioCanvas');
  const slide = getSelectedSlide();
  if (!canvas) return;

  if (!slide) {
    canvas.innerHTML = '<div class="empty" style="margin:18px;">Lege zuerst eine Folie an.</div>';
    canvas.style.background = '#1a1a2e';
    return;
  }

  const bgColor = slide.bg_color || '#1a1a2e';
  const bgImage = slide.bg_image || '';
  canvas.style.background = bgImage ? `${bgColor} url(${bgImage}) center/cover no-repeat` : bgColor;
  canvas.innerHTML = '';

  (slide.blocks || []).forEach((block) => {
    const node = document.createElement('div');
    const x = (Number(block.x || 0) / 1920) * 100;
    const y = (Number(block.y || 0) / 1080) * 100;
    const w = (Number(block.w || 0) / 1920) * 100;
    const h = (Number(block.h || 0) / 1080) * 100;
    node.className = `studio-block ${block.type || 'text'}${block.id === studioState.selectedBlockId ? ' active' : ''}`;
    node.style.left = `${x}%`;
    node.style.top = `${y}%`;
    node.style.width = `${w}%`;
    node.style.height = `${h}%`;
    node.style.color = block.color || '#ffffff';
    node.style.textAlign = block.align || 'left';
    node.dataset.blockId = block.id;

    if (block.type === 'image' && block.src) {
      const img = document.createElement('img');
      img.src = block.src;
      img.alt = '';
      node.appendChild(img);
    } else {
      const inner = document.createElement('div');
      inner.className = 'studio-block-inner';
      if (block.type === 'clock') {
        inner.style.width = '100%';
        inner.style.height = '100%';
        inner.style.transform = 'none';
        inner.style.fontSize = `${getClockFontSize(block)}px`;
      } else {
        const scale = getBlockScale(block);
        inner.style.width = `${100 / scale}%`;
        inner.style.height = `${100 / scale}%`;
        inner.style.transform = `scale(${scale})`;
        inner.style.fontSize = `${Math.max(16, Number(block.font_size) || 42)}px`;
      }
      inner.style.textAlign = block.align || 'left';
      if (block.type === 'clock') {
        inner.textContent = '12:45';
      } else {
        inner.innerHTML = escapeHtml(block.text || 'Textblock').replace(/\n/g, '<br>');
      }
      node.appendChild(inner);
    }

    node.addEventListener('click', () => {
      const now = Date.now();
      const isDoubleClick = studioState.lastClickBlockId === block.id && (now - studioState.lastClickAt) < 320;
      studioState.lastClickBlockId = block.id;
      studioState.lastClickAt = now;
      studioState.selectedBlockId = block.id;
      renderSlidesStudio();
      if (isDoubleClick) {
        openBlockModal(block.id);
      }
    });
    node.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      studioState.selectedBlockId = block.id;
      const point = canvasPointFromEvent(canvas, event.clientX, event.clientY);
      studioState.pointerDownBlockId = block.id;
      studioState.pointerStart = {x: event.clientX, y: event.clientY};
      studioState.isDraggingBlock = false;
      studioState.dragOffset = {
        x: point.x - Number(block.x || 0),
        y: point.y - Number(block.y || 0),
      };
    });

    if (block.id === studioState.selectedBlockId) {
      const menu = document.createElement('div');
      menu.className = 'block-overlay-menu';

      const editButton = document.createElement('button');
      editButton.type = 'button';
      editButton.textContent = '✎';
      editButton.title = 'Bearbeiten';
      editButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openBlockModal(block.id);
      });
      menu.appendChild(editButton);

      const deleteButton = document.createElement('button');
      deleteButton.type = 'button';
      deleteButton.className = 'danger';
      deleteButton.textContent = '🗑';
      deleteButton.title = 'Löschen';
      deleteButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        slide.blocks = slide.blocks.filter((item) => item.id !== block.id);
        studioState.selectedBlockId = slide.blocks[0] ? slide.blocks[0].id : null;
        renderSlidesStudio();
      });
      menu.appendChild(deleteButton);

      const duplicateButton = document.createElement('button');
      duplicateButton.type = 'button';
      duplicateButton.textContent = '⧉';
      duplicateButton.title = 'Duplizieren';
      duplicateButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const duplicate = cloneBlock(block);
        slide.blocks.push(duplicate);
        studioState.selectedBlockId = duplicate.id;
        renderSlidesStudio();
      });
      menu.appendChild(duplicateButton);
      node.appendChild(menu);

      const resize = document.createElement('span');
      resize.className = 'resize-handle se';
      resize.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        event.stopPropagation();
        studioState.selectedBlockId = block.id;
        studioState.resizeBlockId = block.id;
        studioState.resizeHandle = 'se';
        studioState.pointerStart = {x: event.clientX, y: event.clientY};
        studioState.resizeStartRect = {
          x: Number(block.x || 0),
          y: Number(block.y || 0),
          w: Number(block.w || 0),
          h: Number(block.h || 0),
        };
        renderSlidesStudio();
      });
      node.appendChild(resize);
    }
    canvas.appendChild(node);
  });

  studioState.snapGuides.x.forEach((line) => {
    const guide = document.createElement('div');
    guide.className = 'snap-line v';
    guide.style.left = `${(line / 1920) * 100}%`;
    canvas.appendChild(guide);
  });
  studioState.snapGuides.y.forEach((line) => {
    const guide = document.createElement('div');
    guide.className = 'snap-line h';
    guide.style.top = `${(line / 1080) * 100}%`;
    canvas.appendChild(guide);
  });
}

function renderBlockList() {
  const list = document.getElementById('studioBlockList');
  const slide = getSelectedSlide();
  if (!list) return;
  list.innerHTML = '';

  if (!slide || !slide.blocks || !slide.blocks.length) {
    list.innerHTML = '<div class="helper">Keine Blöcke</div>';
    return;
  }

  slide.blocks.forEach((block) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `block-pill${block.id === studioState.selectedBlockId ? ' active' : ''}`;
    button.innerHTML = `<strong>${escapeHtml(blockLabel(block.type))}</strong><small>${Math.round(Number(block.w || 0))}×${Math.round(Number(block.h || 0))}</small>`;
    button.addEventListener('click', () => {
      studioState.selectedBlockId = block.id;
      renderSlidesStudio();
    });
    list.appendChild(button);
  });
}

function renderBlockInspector() {
  return;
}

function getModalBlock() {
  const slide = getSelectedSlide();
  if (!slide || !Array.isArray(slide.blocks)) return null;
  return slide.blocks.find((block) => block.id === studioState.modalBlockId) || null;
}

function syncModalVisibility(block) {
  const isImage = block.type === 'image';
  const isClock = block.type === 'clock';
  document.getElementById('modalTextField').style.display = isImage || isClock ? 'none' : 'grid';
  document.getElementById('modalSrcField').style.display = isImage ? 'grid' : 'none';
  document.getElementById('modalAlignField').style.display = isImage ? 'none' : 'grid';
  document.getElementById('modalColorField').style.display = isImage ? 'none' : 'grid';
  document.getElementById('modalFontSizeField').style.display = isImage ? 'none' : 'grid';
}

function openBlockModal(blockId) {
  const slide = getSelectedSlide();
  if (!slide) return;
  const block = slide.blocks.find((item) => item.id === blockId);
  if (!block) return;
  studioState.modalBlockId = blockId;
  document.getElementById('blockEditMeta').textContent = blockLabel(block.type);
  document.getElementById('modalType').value = block.type || 'text';
  document.getElementById('modalId').value = block.id || '';
  document.getElementById('modalX').value = Number(block.x || 120);
  document.getElementById('modalY').value = Number(block.y || 120);
  document.getElementById('modalW').value = Number(block.w || 420);
  document.getElementById('modalH').value = Number(block.h || 140);
  document.getElementById('modalText').value = block.text || '';
  document.getElementById('modalSrc').value = block.src || '';
  document.getElementById('modalFontSize').value = Number(block.font_size || 42);
  document.getElementById('modalColor').value = block.color || '#ffffff';
  document.getElementById('modalAlign').value = block.align || 'left';
  syncModalVisibility(block);
  document.getElementById('blockEditModal').classList.add('open');
}

function closeBlockModal() {
  studioState.modalBlockId = null;
  document.getElementById('blockEditModal').classList.remove('open');
}

function applyModalToBlock() {
  const block = getModalBlock();
  if (!block) return;
  block.type = document.getElementById('modalType').value;
  block.id = document.getElementById('modalId').value.trim();
  block.x = Number(document.getElementById('modalX').value || 120);
  block.y = Number(document.getElementById('modalY').value || 120);
  block.w = Number(document.getElementById('modalW').value || 420);
  block.h = Number(document.getElementById('modalH').value || 140);
  block.text = document.getElementById('modalText').value;
  block.src = document.getElementById('modalSrc').value.trim();
  block.font_size = Number(document.getElementById('modalFontSize').value || 42);
  block.color = document.getElementById('modalColor').value.trim() || '#ffffff';
  block.align = document.getElementById('modalAlign').value;
  if (!block.base_w || !block.base_h) {
    block.base_w = block.w;
    block.base_h = block.h;
  }
  studioState.selectedBlockId = block.id;
  closeBlockModal();
  renderSlidesStudio();
}

function renderSlidesStudio() {
  ensureSlidesStudioSelection();
  renderSlidesList();
  renderSlideMeta();
  renderSlideFields();
  renderStudioCanvas();
  renderBlockList();
  renderBlockInspector();
}

function blockLabel(type) {
  if (type === 'clock') return 'Uhr-Block';
  if (type === 'image') return 'Bild-Block';
  return 'Text-Block';
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function bindSlidesStudio() {
  const slidesList = document.getElementById('slidesList');
  if (!slidesList) return;
  const canvas = document.getElementById('studioCanvas');

  ensureSlidesStudioSelection();
  renderSlidesStudio();

  const slideBindings = [
    ['studioSlideName', 'name'],
    ['studioSlideId', 'id'],
    ['studioSlideDuration', 'duration'],
    ['studioSlideBgColor', 'bg_color'],
    ['studioSlideBgImage', 'bg_image'],
  ];

  slideBindings.forEach(([elementId, field]) => {
    const input = document.getElementById(elementId);
    if (!input) return;
    input.addEventListener('input', () => {
      const slide = getSelectedSlide();
      if (!slide) return;
      slide[field] = field === 'duration' ? Number(input.value || 10) : input.value;
      if (field === 'id') {
        studioState.selectedSlideId = slide.id;
      }
      renderSlidesStudio();
    });
  });

  const addSlideButton = document.getElementById('addSlide');
  if (addSlideButton) {
    addSlideButton.addEventListener('click', () => {
      const slide = createSlide();
      studioState.slides.push(slide);
      studioState.selectedSlideId = slide.id;
      studioState.selectedBlockId = null;
      renderSlidesStudio();
    });
  }

  document.getElementById('addTextBlock')?.addEventListener('click', () => {
    addStudioBlock('text');
  });

  document.getElementById('addClockBlock')?.addEventListener('click', () => {
    addStudioBlock('clock');
  });

  document.getElementById('addImageBlock')?.addEventListener('click', () => {
    addStudioBlock('image');
  });
  document.getElementById('assignDisplayButton')?.addEventListener('click', async () => {
    await assignSelectedSlideToDisplay();
  });

  document.getElementById('modalType')?.addEventListener('change', () => {
    const block = {
      type: document.getElementById('modalType').value,
    };
    syncModalVisibility(block);
  });

  document.getElementById('closeBlockModal')?.addEventListener('click', closeBlockModal);
  document.getElementById('saveBlockModal')?.addEventListener('click', applyModalToBlock);
  document.getElementById('deleteBlockFromModal')?.addEventListener('click', () => {
    const slide = getSelectedSlide();
    if (!slide || !Array.isArray(slide.blocks)) return;
    slide.blocks = slide.blocks.filter((block) => block.id !== studioState.modalBlockId);
    studioState.selectedBlockId = slide.blocks[0] ? slide.blocks[0].id : null;
    closeBlockModal();
    renderSlidesStudio();
  });

  document.querySelectorAll('.tool-card').forEach((tool) => {
    tool.addEventListener('dragstart', (event) => {
      studioState.dragToolType = tool.dataset.tool || 'text';
      event.dataTransfer?.setData('text/plain', studioState.dragToolType);
      event.dataTransfer.effectAllowed = 'copy';
    });
    tool.addEventListener('dragend', () => {
      studioState.dragToolType = null;
      canvas?.classList.remove('drag-over');
    });
  });

  const menu = document.getElementById('canvasContextMenu');
  const modal = document.getElementById('blockEditModal');
  if (canvas && menu) {
    const hideMenu = () => { menu.style.display = 'none'; };
    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) {
        hideMenu();
      }
    });

    canvas.addEventListener('contextmenu', (event) => {
      event.preventDefault();
      studioState.contextPosition = canvasPointFromEvent(canvas, event.clientX, event.clientY);
      menu.style.left = `${event.clientX}px`;
      menu.style.top = `${event.clientY}px`;
      menu.style.display = 'block';
    });

    canvas.addEventListener('dragover', (event) => {
      if (!studioState.dragToolType) return;
      event.preventDefault();
      canvas.classList.add('drag-over');
    });

    canvas.addEventListener('dragleave', () => {
      canvas.classList.remove('drag-over');
    });

    canvas.addEventListener('drop', (event) => {
      if (!studioState.dragToolType) return;
      event.preventDefault();
      const type = studioState.dragToolType;
      studioState.dragToolType = null;
      canvas.classList.remove('drag-over');
      addStudioBlock(type, canvasPointFromEvent(canvas, event.clientX, event.clientY));
    });

    canvas.addEventListener('pointerdown', (event) => {
      if (event.target === canvas) {
        studioState.selectedBlockId = null;
        studioState.lastClickBlockId = null;
        renderSlidesStudio();
      }
    });

    canvas.addEventListener('pointermove', (event) => {
      const slide = getSelectedSlide();
      if (!slide) return;

      if (studioState.resizeBlockId && studioState.resizeHandle && studioState.resizeStartRect) {
        const block = slide.blocks.find((item) => item.id === studioState.resizeBlockId);
        if (!block) return;
        const deltaX = event.clientX - (studioState.pointerStart?.x ?? event.clientX);
        const deltaY = event.clientY - (studioState.pointerStart?.y ?? event.clientY);
        const pxX = deltaX * (1920 / canvas.clientWidth);
        const pxY = deltaY * (1080 / canvas.clientHeight);
        const minW = 120;
        const minH = 72;
        const start = studioState.resizeStartRect;

        let nextX = start.x;
        let nextY = start.y;
        let nextW = start.w;
        let nextH = start.h;

        if (studioState.resizeHandle.includes('e')) {
          nextW = clamp(start.w + pxX, minW, 1920 - start.x);
        }
        if (studioState.resizeHandle.includes('s')) {
          nextH = clamp(start.h + pxY, minH, 1080 - start.y);
        }
        if (studioState.resizeHandle.includes('w')) {
          nextX = clamp(start.x + pxX, 0, start.x + start.w - minW);
          nextW = clamp(start.w - (nextX - start.x), minW, 1920 - nextX);
        }
        if (studioState.resizeHandle.includes('n')) {
          nextY = clamp(start.y + pxY, 0, start.y + start.h - minH);
          nextH = clamp(start.h - (nextY - start.y), minH, 1080 - nextY);
        }

        block.x = Math.round(nextX);
        block.y = Math.round(nextY);
        block.w = Math.round(nextW);
        block.h = Math.round(nextH);
        renderSlidesStudio();
        return;
      }

      if (!studioState.pointerDownBlockId) return;
      const deltaX = Math.abs(event.clientX - (studioState.pointerStart?.x ?? event.clientX));
      const deltaY = Math.abs(event.clientY - (studioState.pointerStart?.y ?? event.clientY));
      if (!studioState.isDraggingBlock && deltaX + deltaY < 6) {
        return;
      }
      if (!studioState.isDraggingBlock) {
        studioState.isDraggingBlock = true;
        studioState.dragBlockId = studioState.pointerDownBlockId;
      }
      if (!studioState.dragBlockId) return;
      const block = slide.blocks.find((item) => item.id === studioState.dragBlockId);
      if (!block) return;
      const point = canvasPointFromEvent(canvas, event.clientX, event.clientY);
      const rawX = clamp(point.x - studioState.dragOffset.x, 0, 1920 - Number(block.w || 0));
      const rawY = clamp(point.y - studioState.dragOffset.y, 0, 1080 - Number(block.h || 0));
      const snapped = applySnapToBlock(block, rawX, rawY, getSnapCandidates(slide, block.id));
      block.x = clamp(snapped.x, 0, 1920 - Number(block.w || 0));
      block.y = clamp(snapped.y, 0, 1080 - Number(block.h || 0));
      studioState.snapGuides = snapped.guides;
      renderSlidesStudio();
    });

    const stopDragging = () => {
      studioState.dragBlockId = null;
      studioState.pointerDownBlockId = null;
      studioState.pointerStart = null;
      studioState.isDraggingBlock = false;
      studioState.resizeBlockId = null;
      studioState.resizeHandle = null;
      studioState.resizeStartRect = null;
      clearSnapGuides();
    };
    canvas.addEventListener('pointerup', stopDragging);
    canvas.addEventListener('pointerleave', stopDragging);

    menu.querySelectorAll('[data-context-action]').forEach((button) => {
      button.addEventListener('click', async () => {
        const action = button.dataset.contextAction;
        hideMenu();
        if (action === 'add-text') addStudioBlock('text', studioState.contextPosition);
        if (action === 'add-clock') addStudioBlock('clock', studioState.contextPosition);
        if (action === 'add-image') addStudioBlock('image', studioState.contextPosition);
        if (action === 'assign-display') await assignSelectedSlideToDisplay();
      });
    });
  }

  modal?.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeBlockModal();
    }
  });
}

const settingsForm = document.getElementById('settingsForm');
if (settingsForm) {
  settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setStatus('settingsStatus', 'Speichert...', 'idle');
    const payload = {
      branding: {name: settingsForm.branding_name.value.trim()},
      system: {
        timezone: settingsForm.timezone.value.trim(),
        language: settingsForm.language.value.trim(),
        default_slide_duration: Number(settingsForm.default_slide_duration.value || 10),
        default_transition: settingsForm.default_transition.value,
      },
      weather: {
        enabled: settingsForm.weather_enabled.checked,
        location: settingsForm.weather_location.value.trim(),
        provider: settingsForm.weather_provider.value.trim(),
      },
      maintenance: {
        enabled: settingsForm.maintenance_enabled.checked,
        message: settingsForm.maintenance_message.value.trim(),
      },
    };

    try {
      await postJson('../api/settings.php', payload);
      setStatus('settingsStatus', 'Einstellungen gespeichert', 'success');
    } catch (error) {
      setStatus('settingsStatus', error.message, 'error');
    }
  });
}

const saveDisplays = document.getElementById('saveDisplays');
if (saveDisplays) {
  saveDisplays.addEventListener('click', async () => {
    setStatus('displaysStatus', 'Speichert...', 'idle');
    const items = serializeRows('displaysEditor', (row) => ({
      name: fieldValue(row, 'name'),
      id: fieldValue(row, 'id'),
      default_playlist_id: fieldValue(row, 'default_playlist_id'),
      token: fieldValue(row, 'token'),
      last_seen_at: fieldValue(row, 'last_seen_at'),
    }));

    try {
      await postJson('../api/displays.php', {items});
      setStatus('displaysStatus', 'Displays gespeichert', 'success');
    } catch (error) {
      setStatus('displaysStatus', error.message, 'error');
    }
  });
}

const savePlaylists = document.getElementById('savePlaylists');
if (savePlaylists) {
  savePlaylists.addEventListener('click', async () => {
    setStatus('playlistsStatus', 'Speichert...', 'idle');
    const items = serializeRows('playlistsEditor', (row) => ({
      name: fieldValue(row, 'name'),
      id: fieldValue(row, 'id'),
      slide_ids: splitCsv(fieldValue(row, 'slide_ids')),
    }));

    try {
      await postJson('../api/playlists.php', {items});
      setStatus('playlistsStatus', 'Playlists gespeichert', 'success');
    } catch (error) {
      setStatus('playlistsStatus', error.message, 'error');
    }
  });
}

const saveSchedules = document.getElementById('saveSchedules');
if (saveSchedules) {
  saveSchedules.addEventListener('click', async () => {
    setStatus('schedulesStatus', 'Speichert...', 'idle');
    const items = serializeRows('schedulesEditor', (row) => ({
      name: fieldValue(row, 'name'),
      id: fieldValue(row, 'id'),
      display_id: fieldValue(row, 'display_id'),
      playlist_id: fieldValue(row, 'playlist_id'),
      from: fieldValue(row, 'from'),
      to: fieldValue(row, 'to'),
      days: splitCsv(fieldValue(row, 'days')),
    }));

    try {
      await postJson('../api/schedules.php', {items});
      setStatus('schedulesStatus', 'Zeitfenster gespeichert', 'success');
    } catch (error) {
      setStatus('schedulesStatus', error.message, 'error');
    }
  });
}

const saveSlides = document.getElementById('saveSlides');
if (saveSlides) {
  saveSlides.addEventListener('click', async () => {
    setStatus('slidesStatus', 'Speichert...', 'idle');
    const items = studioState.slides.map((slide) => ({
      id: slide.id,
      name: slide.name,
      duration: Number(slide.duration || 10),
      bg_color: slide.bg_color || '#1a1a2e',
      bg_image: slide.bg_image || '',
      blocks: Array.isArray(slide.blocks) ? slide.blocks : [],
    }));

    try {
      await postJson('../api/slides.php', {items});
      setStatus('slidesStatus', 'Slides gespeichert', 'success');
    } catch (error) {
      setStatus('slidesStatus', error.message, 'error');
    }
  });
}

document.addEventListener('keydown', async (event) => {
  const activeTag = document.activeElement?.tagName;
  const isTyping = activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT';

  if (!isTyping && studioState.selectedBlockId && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
    event.preventDefault();
    const slide = getSelectedSlide();
    const block = getSelectedBlock();
    if (slide && block) {
      const step = event.shiftKey ? 10 : 1;
      if (event.key === 'ArrowLeft') block.x = clamp(Number(block.x || 0) - step, 0, 1920 - Number(block.w || 0));
      if (event.key === 'ArrowRight') block.x = clamp(Number(block.x || 0) + step, 0, 1920 - Number(block.w || 0));
      if (event.key === 'ArrowUp') block.y = clamp(Number(block.y || 0) - step, 0, 1080 - Number(block.h || 0));
      if (event.key === 'ArrowDown') block.y = clamp(Number(block.y || 0) + step, 0, 1080 - Number(block.h || 0));
      renderSlidesStudio();
    }
  }

  if (event.key === 'Delete' && studioState.selectedBlockId) {
    if (!isTyping) {
      const slide = getSelectedSlide();
      if (slide) {
        slide.blocks = (slide.blocks || []).filter((block) => block.id !== studioState.selectedBlockId);
        studioState.selectedBlockId = slide.blocks[0] ? slide.blocks[0].id : null;
        renderSlidesStudio();
      }
    }
  }

  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault();
    document.getElementById('saveSlides')?.click();
  }
});
</script>
</body>
</html>


