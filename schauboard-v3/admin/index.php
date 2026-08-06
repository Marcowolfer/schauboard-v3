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
        $setupError = t('auth.setup.error_short', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
    } elseif ($password !== $confirm) {
        $setupError = t('auth.setup.error_mismatch', 'Die beiden Passwoerter stimmen nicht ueberein.');
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!$hash || !schauboard_store_password_hash($hash)) {
            $setupError = t('auth.setup.error_store', 'Die Passwortdatei konnte nicht gespeichert werden.');
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
    'editor' => t('admin.nav.editor', 'Folien'),
    'playlists' => t('admin.nav.playlists', 'Playlists'),
    'displays' => t('admin.nav.displays', 'Displays'),
    'schedules' => t('admin.nav.schedules', 'Zeitpläne'),
    'settings' => t('admin.nav.settings', 'Einstellungen'),
];
$sectionHints = [
    'editor' => t('admin.hint.editor', 'Folien gestalten: Block auf die Bühne ziehen, anklicken zum Bearbeiten, ziehen zum Verschieben.'),
    'playlists' => t('admin.hint.playlists', 'Folien zu Playlists bündeln. Ein Display zeigt immer eine Playlist.'),
    'displays' => t('admin.hint.displays', 'Deine Bildschirme: Name vergeben, URL öffnen, Playlist zuweisen – fertig.'),
    'schedules' => t('admin.hint.schedules', 'Optional: zu bestimmten Zeiten automatisch eine andere Playlist zeigen.'),
    'settings' => t('admin.hint.settings', 'Globale Einstellungen, Branding und Wartungsmodus.'),
];

// "Heute" in der Settings-Zeitzone: massgeblich fuer die Gueltigkeits-Badges im
// Editor, damit sie zum Display-Verhalten passen (das rechnet mit derselben TZ,
// nicht mit der Browser-Uhr des Admin-Rechners).
try {
    $adminNow = new DateTime('now', new DateTimeZone($settings['system']['timezone'] ?? 'Europe/Zurich'));
} catch (Exception $e) {
    $adminNow = new DateTime('now');
}

$jsState = [
    'settings' => $settings,
    'today' => $adminNow->format('Y-m-d'),
    'slides' => $slides,
    'playlists' => $playlists,
    'displays' => $displays,
    'schedules' => $schedules,
    'heartbeats' => (object) $heartbeats,
    'offlineTimeoutMin' => (int) ($settings['system']['offline_timeout_minutes'] ?? 5),
    'templates' => schauboard_read_dataset('templates'),
];
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(schauboard_language()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($version['name'] ?? 'Schauboard') . ' Admin') ?></title>
<link rel="stylesheet" href="../assets/blocks.css?v=<?= htmlspecialchars($version['current'] ?? '0') ?>">
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
.brand-logo{height:30px;width:auto;display:block;background:#fff;border-radius:8px;padding:5px 10px;box-shadow:0 2px 10px rgba(0,0,0,.25)}
.update-banner{background:linear-gradient(90deg,rgba(95,140,255,.20),rgba(115,223,196,.14));border:1px solid rgba(95,140,255,.55);border-radius:14px;margin:0 0 14px;padding:11px 16px;box-shadow:0 8px 28px rgba(0,0,0,.28)}
.update-banner .ub-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.update-banner .ub-icon{font-size:1.25rem;line-height:1}
.update-banner .ub-text{flex:1 1 280px;font-weight:600;color:var(--text);line-height:1.35}
.update-banner .ub-text small{display:block;font-weight:500;color:var(--muted);margin-top:2px}
.update-banner .ub-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.update-banner .ub-howto{margin-top:11px;border-top:1px solid var(--line);padding-top:10px;color:var(--muted);font-size:.92rem}
.update-banner .ub-howto ol{margin:0;padding-left:20px}
.update-banner .ub-howto li{margin:4px 0}
.update-banner .ub-howto code{background:rgba(255,255,255,.09);border-radius:5px;padding:1px 5px;font-family:Consolas,monospace}
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
/* Dropdown-Eintraege im aufgeklappten Menue lesbar machen (sonst kontrastarm im Dark Mode) */
select option{background:#0f1a2b;color:#f5f7fb}
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
.editor-workspace{flex:1;min-height:0;display:grid;grid-template-columns:340px minmax(0,1fr);gap:0;border:1px solid rgba(255,255,255,.06);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.02)}
.editor-sidebar{border-right:1px solid rgba(255,255,255,.06);background:rgba(12,18,32,.7);display:grid;grid-template-rows:minmax(0,1fr) auto;min-height:0}
.editor-side-section{padding:12px;border-bottom:1px solid rgba(255,255,255,.06);overflow:auto}
.editor-side-section h4{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7f8ead;margin-bottom:10px}
.editor-side-list{display:grid;grid-template-columns:minmax(0,1fr);gap:7px}
.slide-item-btn,.block-pill{width:100%;padding:9px 11px;border-radius:11px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);color:var(--text);text-align:left;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px}
.slide-item-btn.active,.block-pill.active{border-color:rgba(95,140,255,.3);background:linear-gradient(135deg, rgba(95,140,255,.2), rgba(115,223,196,.08))}
.slide-item-btn strong,.block-pill strong{display:block;font-size:13px}
.slide-item-btn small,.block-pill small{display:block;color:var(--muted);margin-top:3px;font-size:11px}
/* Folien + Ebenen NEBENEINANDER: zwei gleich hohe Spalten statt gestapelt */
.editor-side-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);min-height:0;border-bottom:1px solid rgba(255,255,255,.08)}
.editor-side-cols .editor-side-section{min-height:0;overflow:auto;border-bottom:none;padding-top:0;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.18) transparent}
.editor-side-cols .es-slides{border-right:1px solid rgba(255,255,255,.06)}
/* Ueberschriften bleiben beim Scrollen als Trenner oben stehen */
.editor-side-cols .editor-side-section h4{position:sticky;top:0;z-index:1;margin:0 0 10px;padding:12px 0 8px;background:rgba(12,18,32,.94);backdrop-filter:blur(2px)}
#blockList{overflow-y:auto}
/* Vorlagen: einklappbare Fussleiste ueber die volle Sidebar-Breite */
.editor-side-templates{background:rgba(255,255,255,.015)}
.editor-side-templates>summary{list-style:none;cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;padding:11px 12px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7f8ead}
.editor-side-templates>summary::-webkit-details-marker{display:none}
.editor-side-templates>summary::before{content:"\25B8";font-size:9px;color:#5f6f8c;transition:transform .16s ease}
.editor-side-templates[open]>summary::before{transform:rotate(90deg)}
.editor-side-templates>summary:hover{color:#c8d3ea}
.editor-side-templates .es-tpl-body{padding:0 12px 12px;max-height:26vh;overflow:auto;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.18) transparent}
/* Folienliste fuellt jetzt ihre Spalte (kein fixer 40vh-Deckel mehr; die Sektion scrollt); Drag&Drop bleibt vertikal */
#slidesList{max-height:none;padding-right:2px}
.slide-item-btn{cursor:grab;position:relative}
.slide-item-btn.dragging{opacity:.4}
.slide-item-btn.drop-before{box-shadow:inset 0 3px 0 0 var(--accent2)}
.slide-item-btn.drop-after{box-shadow:inset 0 -3px 0 0 var(--accent2)}
.slide-item-btn .si-grip{flex:0 0 auto;opacity:.35;font-size:15px;line-height:1;letter-spacing:-2px}
.slide-item-btn .si-info{flex:1 1 auto;min-width:0;padding-right:15px}
/* Loeschen-X absolut oben rechts -> konkurriert nie um die (schmale) Spaltenbreite */
.slide-item-btn .si-del{position:absolute;top:0;right:0;cursor:pointer;opacity:.5;padding:7px 8px;line-height:1}
.slide-item-btn .si-del:hover{opacity:1}
/* Datums-Gueltigkeit: Badge in der Folienliste (gruen = aktuell sichtbar, rot = zurzeit inaktiv) */
.si-valid{display:block;margin-top:3px;font-size:10px;font-weight:700;color:var(--accent2)}
.si-valid.off{color:var(--danger)}
.pl-valid{flex:0 0 auto;font-size:12px;opacity:.8;cursor:help}
.pl-valid.off{filter:grayscale(1);opacity:.55}
.pl-slide .pl-inactive{flex:0 0 auto;font-size:10px;font-weight:700;color:var(--danger);border:1px solid rgba(255,142,161,.35);border-radius:999px;padding:1px 7px}
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
/* Playlist: sortierbare Folienliste (Reihenfolge = Anzeige-Reihenfolge) */
.pl-slides{display:grid;grid-template-columns:minmax(0,1fr);gap:7px}
.pl-slide{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:10px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);cursor:grab}
.pl-slide.dragging{opacity:.4}
.pl-slide.drop-before{box-shadow:inset 0 3px 0 0 var(--accent2)}
.pl-slide.drop-after{box-shadow:inset 0 -3px 0 0 var(--accent2)}
.pl-slide .si-grip{flex:0 0 auto;opacity:.35;font-size:15px;line-height:1;letter-spacing:-2px}
.pl-slide .pl-slide-name{flex:1 1 auto;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pl-slide .pl-num{color:var(--muted);margin-right:3px}
.pl-slide .pl-del{flex:0 0 auto;cursor:pointer;opacity:.5;padding:2px 5px;border-radius:6px}
.pl-slide .pl-del:hover{opacity:1;color:var(--danger)}
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
/* Farbfelder: natives Farbwaehler-Swatch neben dem Hex-Feld */
.color-row{display:flex;gap:8px;align-items:center}
.color-row input[type=color]{flex:0 0 46px;width:46px;min-height:38px;padding:3px;border-radius:11px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);cursor:pointer}
.color-row input[type=text]{flex:1;min-width:0}
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
  /* Auf schmalen Fenstern die zwei Spalten wieder untereinander stapeln */
  .editor-side-cols{grid-template-columns:1fr}
  .editor-side-cols .es-slides{border-right:none;border-bottom:1px solid rgba(255,255,255,.06)}
  .editor-side-cols .editor-side-section h4{position:static;backdrop-filter:none}
  #slidesList{max-height:34vh}
  .editor-side-templates .es-tpl-body{max-height:none}
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
      <a class="btn" href="../?display=default" target="_blank" rel="noreferrer"><?= te('admin.view_display', '▶ Display ansehen') ?></a>
      <form method="post" style="margin:0"><button type="submit" class="btn" name="logout" value="1"><?= te('common.logout', 'Abmelden') ?></button></form>
    </div>
  </header>
  <div id="updateBanner" class="update-banner" hidden>
    <div class="ub-row">
      <span class="ub-icon">⬆️</span>
      <span class="ub-text"></span>
      <span class="ub-actions">
        <button type="button" class="btn small primary" id="ubApply" hidden><?= te('update.apply', '⬆️ Jetzt aktualisieren') ?></button>
        <a class="btn small" id="ubDownload" target="_blank" rel="noreferrer" hidden><?= te('update.download', '⬇ Herunterladen') ?></a>
        <button type="button" class="btn small" id="ubHowtoBtn"><?= te('update.howto', '📋 Anleitung') ?></button>
        <button type="button" class="btn small" id="ubDismiss" title="<?= te('update.dismiss_title', 'Diese Version ausblenden') ?>">✕</button>
      </span>
    </div>
    <div class="ub-howto" id="ubHowtoPanel" hidden>
      <ol>
        <li><?= te('update.step1', 'ZIP herunterladen und entpacken.') ?></li>
        <li><?= t('update.step2', 'Alle Dateien <strong>außer</strong> <code>data/</code>, <code>uploads/</code> und <code>config.local.php</code> in deinen Schauboard-Ordner kopieren und die vorhandenen überschreiben.') ?></li>
        <li><?= te('update.step3', 'Seite neu laden – deine Folien, Einstellungen und Bilder bleiben erhalten.') ?></li>
      </ol>
    </div>
  </div>
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
            <button type="button" class="btn" id="previewSlide"><?= te('editor.preview', '👁 Vorschau') ?></button>
            <button type="button" class="btn primary" id="saveSlides"><?= te('common.save', '💾 Speichern') ?></button>
          <?php elseif ($section === 'playlists'): ?>
            <button type="button" class="btn" id="addPlaylist"><?= te('playlist.add', '+ Playlist') ?></button>
            <button type="button" class="btn primary" id="savePlaylists"><?= te('common.save', '💾 Speichern') ?></button>
          <?php elseif ($section === 'displays'): ?>
            <button type="button" class="btn" id="addDisplay"><?= te('display.add', '+ Display') ?></button>
            <button type="button" class="btn primary" id="saveDisplays"><?= te('common.save', '💾 Speichern') ?></button>
          <?php elseif ($section === 'schedules'): ?>
            <button type="button" class="btn" id="addSchedule"><?= te('schedule.add', '+ Zeitplan') ?></button>
            <button type="button" class="btn primary" id="saveSchedules"><?= te('common.save', '💾 Speichern') ?></button>
          <?php elseif ($section === 'settings'): ?>
            <button type="button" class="btn primary" id="saveSettingsTop"><?= te('common.save', '💾 Speichern') ?></button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($section === 'editor'): ?>
        <section class="card editor-card">
          <div class="editor-workspace">
            <aside class="editor-sidebar">
              <div class="editor-side-cols">
                <section class="editor-side-section es-slides">
                  <h4><?= te('editor.slides', 'Folien') ?></h4>
                  <div id="slidesList" class="editor-side-list"></div>
                  <button type="button" class="btn small" id="addSlide" style="width:100%;margin-top:10px;"><?= te('editor.add_slide', '+ Folie') ?></button>
                </section>
                <section class="editor-side-section es-layers">
                  <h4><?= te('editor.layers', 'Ebenen') ?></h4>
                  <div id="blockList" class="editor-side-list"></div>
                </section>
              </div>
              <details class="editor-side-templates" open>
                <summary><?= te('editor.templates', 'Vorlagen') ?></summary>
                <div class="es-tpl-body">
                  <div id="templateList" class="editor-side-list"></div>
                  <button type="button" class="btn small" id="saveAsTemplate" style="width:100%;margin-top:10px;"><?= te('editor.save_as_template', '★ Folie als Vorlage') ?></button>
                  <div class="row" style="margin-top:8px;gap:6px;">
                    <button type="button" class="btn small" id="exportSlideBtn" style="flex:1;"><?= te('editor.export_slide', '📤 Export') ?></button>
                    <button type="button" class="btn small" id="importSlideBtn" style="flex:1;"><?= te('editor.import_slide', '📥 Import') ?></button>
                  </div>
                  <input type="file" id="importSlideInput" accept="application/json,.json" hidden>
                </div>
              </details>
            </aside>
            <section class="editor-main">
              <div class="editor-toolbar">
                <div class="tool-palette" id="toolPalette"></div>
                <div class="toolbar-sep"></div>
                <div class="toolbar-group">
                  <label><?= te('editor.field.name', 'Name') ?> <input type="text" id="slideName" placeholder="<?= te('editor.field.name_placeholder', 'Folienname') ?>"></label>
                  <label><?= te('editor.field.duration', 'Dauer') ?> <input type="number" min="2" max="600" id="slideDuration">s</label>
                </div>
              </div>
              <div class="editor-stage-wrap">
                <div id="studioCanvas" class="studio-canvas"></div>
              </div>
              <div class="editor-bottombar">
                <div class="muted" id="editorMeta"><?= te('editor.no_slide', 'Noch keine Folie ausgewählt.') ?></div>
                <details>
                  <summary class="muted" style="cursor:pointer;"><?= te('editor.slide_settings', 'Folie anpassen') ?></summary>
                  <div class="row" style="margin-top:10px;">
                    <label class="field">ID<input type="text" id="slideId"></label>
                    <label class="field"><?= te('editor.slide.bg_color', 'Hintergrundfarbe') ?><span class="color-row"><input type="color" id="slideBgColorPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="slideBgColor" placeholder="#1a1a2e"></span></label>
                    <label class="field"><?= te('editor.slide.bg_image', 'Hintergrundbild (URL)') ?><input type="text" id="slideBgImage" placeholder="<?= te('common.optional', 'optional') ?>"></label>
                    <label class="field"><?= te('editor.slide.valid_from', 'Gültig von') ?><input type="date" id="slideValidFrom" title="<?= te('editor.slide.valid_from_hint', 'Leer = ab sofort') ?>"></label>
                    <label class="field"><?= te('editor.slide.valid_until', 'Gültig bis') ?><input type="date" id="slideValidUntil" title="<?= te('editor.slide.valid_until_hint', 'Leer = unbegrenzt') ?>"></label>
                  </div>
                  <div class="muted" style="margin-top:8px;"><?= te('editor.slide.validity_hint', 'Gültigkeit: Ausserhalb des Zeitraums überspringt das Display die Folie automatisch (z. B. für Ferien oder Aktionen). Leer = immer sichtbar.') ?></div>
                </details>
              </div>
            </section>
          </div>
        </section>
      <?php elseif ($section === 'settings'): ?>
        <section class="card">
          <form id="settingsForm" class="form-grid">
            <label class="field"><?= te('settings.product_name', 'Produktname') ?><input type="text" name="branding_name" value="<?= htmlspecialchars($settings['branding']['name'] ?? 'Schauboard') ?>"></label>
            <label class="field"><?= te('settings.timezone', 'Zeitzone') ?><input type="text" name="timezone" value="<?= htmlspecialchars($settings['system']['timezone'] ?? 'Europe/Zurich') ?>"></label>
            <label class="field">Sprache / Language
              <select name="language">
                <?php foreach (schauboard_available_languages() as $code => $label): ?>
                  <option value="<?= htmlspecialchars($code) ?>" <?= (($settings['system']['language'] ?? 'de') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><?= te('settings.default_duration', 'Standard-Dauer pro Folie (s)') ?><input type="number" min="2" max="600" name="default_slide_duration" value="<?= (int) ($settings['system']['default_slide_duration'] ?? 10) ?>"></label>
            <label class="field"><?= te('settings.default_transition', 'Standard-Übergang') ?>
              <select name="default_transition">
                <?php foreach (['fade' => t('settings.transition.fade', 'Überblenden'), 'slide-left' => t('settings.transition.slide_left', 'Schieben (links)'), 'slide-up' => t('settings.transition.slide_up', 'Schieben (hoch)'), 'zoom' => t('settings.transition.zoom', 'Zoom'), 'none' => t('settings.transition.none', 'Ohne')] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= (($settings['system']['default_transition'] ?? 'fade') === $val) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><?= te('settings.offline_threshold', 'Offline-Schwelle (Min.)') ?><input type="number" min="1" max="120" name="offline_timeout_minutes" value="<?= (int) ($settings['system']['offline_timeout_minutes'] ?? 5) ?>"></label>
            <label class="field"><?= te('settings.weather_location', 'Wetter-Standardort') ?><input type="text" name="weather_location" value="<?= htmlspecialchars($settings['weather']['location'] ?? 'Zurich,CH') ?>"></label>
            <label class="checkbox full"><input type="checkbox" name="weather_enabled" <?= !empty($settings['weather']['enabled']) ? 'checked' : '' ?>> <?= te('settings.weather_enabled', 'Wetter-Module aktivieren') ?></label>
            <label class="checkbox full"><input type="checkbox" name="maintenance_enabled" <?= !empty($settings['maintenance']['enabled']) ? 'checked' : '' ?>> <?= te('settings.maintenance_enabled', 'Wartungsmodus (zeigt allen Displays einen Hinweis)') ?></label>
            <label class="field full"><?= te('settings.maintenance_message', 'Wartungsmeldung') ?><textarea name="maintenance_message"><?= htmlspecialchars($settings['maintenance']['message'] ?? '') ?></textarea></label>
            <div class="full row spread">
              <span class="muted"><?= te('settings.saves_to', 'Speichert nach') ?> <code>data/settings.json</code></span>
              <button type="submit" class="btn primary"><?= te('settings.save', '💾 Einstellungen speichern') ?></button>
            </div>
          </form>
        </section>
        <section class="card" style="margin-top:18px;">
          <h3 style="margin:0 0 6px;"><?= te('settings.backup.title', 'Sichern & Umzug') ?></h3>
          <p class="muted" style="margin:0 0 14px;"><?= te('settings.backup.hint', 'Alle Inhalte (Folien, Playlists, Displays, Zeitpläne, Einstellungen, Vorlagen) in eine Datei sichern – ideal als Backup oder für den Umzug auf eine neue Installation.') ?></p>
          <div class="row">
            <button type="button" class="btn" id="exportBackupBtn"><?= te('settings.backup.export', '⬇ Komplett-Backup exportieren') ?></button>
            <button type="button" class="btn danger" id="importBackupBtn"><?= te('settings.backup.import', '⬆ Backup importieren …') ?></button>
            <input type="file" id="importBackupInput" accept="application/json,.json" hidden>
          </div>
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
      <div><h4 id="modalTitle"><?= te('modal.title.default', 'Block bearbeiten') ?></h4><div class="muted" id="modalSub"></div></div>
      <button type="button" class="btn" id="closeModal"><?= te('common.close', 'Schliessen') ?></button>
    </div>
    <div class="form-grid">
      <label class="field" data-f="type"><?= te('modal.type', 'Typ') ?>
        <select id="mType"></select>
      </label>
      <label class="field full" data-f="text"><?= te('modal.content', 'Inhalt') ?><textarea id="mText"></textarea></label>
      <label class="field" data-f="src"><?= te('modal.src', 'Bild-/Video-URL / Pfad') ?>
        <input type="text" id="mSrc">
      </label>
      <label class="field" data-f="upload"><?= te('modal.upload', 'Bild/Video hochladen') ?>
        <input type="file" id="mUpload" accept="image/*,video/*">
      </label>
      <label class="field" data-f="fit"><?= te('modal.fit', 'Darstellung') ?>
        <select id="mFit"><option value="cover"><?= te('modal.fit.cover', 'Füllen (cover)') ?></option><option value="contain"><?= te('modal.fit.contain', 'Einpassen (contain)') ?></option><option value="fill"><?= te('modal.fit.fill', 'Strecken') ?></option></select>
      </label>
      <label class="field" data-f="shape_kind"><?= te('modal.shape_kind', 'Form') ?>
        <select id="mShapeKind"><option value="rect"><?= te('modal.shape.rect', 'Rechteck') ?></option><option value="ellipse"><?= te('modal.shape.ellipse', 'Ellipse / Kreis') ?></option></select>
      </label>
      <label class="field" data-f="opacity"><?= te('modal.opacity', 'Deckkraft (%)') ?><input type="number" id="mOpacity" min="5" max="100"></label>
      <label class="field" data-f="radius"><?= te('modal.radius', 'Ecken-Radius (nur Rechteck)') ?><input type="number" id="mRadius" min="0" max="400"></label>
      <div class="full" data-f="gallery" style="display:grid;gap:10px;">
        <label class="field"><?= te('modal.gallery.list', 'Bilder der Diashow – ein Bild (URL/Pfad) pro Zeile, Reihenfolge = Abspielreihenfolge') ?>
          <textarea id="mGalleryList" style="min-height:110px;font-family:Consolas,monospace;font-size:12px;" placeholder="uploads/bild1.jpg&#10;uploads/bild2.jpg&#10;https://…"></textarea>
        </label>
        <div class="form-grid">
          <label class="field"><?= te('modal.gallery.upload', 'Bilder hochladen (mehrere möglich)') ?>
            <input type="file" id="mGalleryUpload" accept="image/*" multiple>
          </label>
          <label class="field"><?= te('modal.gallery.interval', 'Wechsel alle … Sekunden') ?><input type="number" id="mGalleryInterval" min="2" max="120"></label>
        </div>
      </div>
      <label class="field" data-f="city"><?= te('modal.city', 'Ort') ?><input type="text" id="mCity"></label>
      <label class="checkbox" data-f="forecast"><input type="checkbox" id="mForecast"> <?= te('modal.forecast', '3-Tage-Vorschau anzeigen') ?></label>
      <label class="field" data-f="rss_url"><?= te('modal.rss_url', 'Feed-URL (RSS oder Atom)') ?><input type="text" id="mRssUrl" placeholder="https://…/feed.xml"></label>
      <label class="field" data-f="rss_count"><?= te('modal.rss_count', 'Anzahl Meldungen (1–15)') ?><input type="number" id="mRssCount" min="1" max="15"></label>
      <label class="checkbox" data-f="rss_time"><input type="checkbox" id="mRssTime"> <?= te('modal.rss_time', 'Zeit anzeigen (z. B. «vor 2 Std.»)') ?></label>
      <label class="checkbox" data-f="rss_source"><input type="checkbox" id="mRssSource"> <?= te('modal.rss_source', 'Feed-Titel als Überschrift') ?></label>
      <label class="field" data-f="clock_format"><?= te('modal.clock_format', 'Format') ?>
        <select id="mClockFormat"><option value="HH:MM">HH:MM</option><option value="HH:MM:SS">HH:MM:SS</option></select>
      </label>
      <label class="checkbox" data-f="show_date"><input type="checkbox" id="mShowDate"> <?= te('modal.show_date', 'Datum anzeigen') ?></label>
      <label class="field" data-f="speed"><?= te('modal.speed', 'Tempo (10–200)') ?><input type="number" id="mSpeed" min="10" max="200"></label>
      <label class="field" data-f="bg"><?= te('modal.bg', 'Hintergrund') ?><span class="color-row"><input type="color" id="mBgPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mBg"></span></label>
      <label class="field" data-f="url"><?= te('modal.url', 'Webseiten-URL') ?><input type="text" id="mUrl" placeholder="https://…"></label>
      <label class="field" data-f="refresh_minutes"><?= te('modal.refresh_minutes', 'Neu laden alle … Min. (0 = nie)') ?><input type="number" id="mRefresh" min="0" max="1440"></label>
      <label class="field" data-f="zoom"><?= te('modal.zoom', 'Zoom (%)') ?><input type="number" id="mZoom" min="25" max="200"></label>
      <label class="field" data-f="data"><?= te('modal.qr_data', 'QR-Inhalt (URL/Text)') ?><input type="text" id="mData"></label>
      <label class="field" data-f="qlabel"><?= te('modal.qr_label', 'Beschriftung') ?><input type="text" id="mQLabel"></label>
      <label class="field" data-f="target"><?= te('modal.target', 'Zieltermin') ?><input type="datetime-local" id="mTarget"></label>
      <label class="field" data-f="clabel"><?= te('modal.countdown_label', 'Beschriftung') ?><input type="text" id="mCLabel"></label>
      <label class="field" data-f="font_size"><?= te('modal.font_size', 'Schriftgrösse') ?><input type="number" id="mFont" min="10" max="400"></label>
      <label class="field" data-f="color"><?= te('modal.color', 'Farbe') ?><span class="color-row"><input type="color" id="mColorPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mColor"></span></label>
      <label class="field" data-f="align"><?= te('modal.align', 'Ausrichtung') ?>
        <select id="mAlign"><option value="left"><?= te('modal.align.left', 'Links') ?></option><option value="center"><?= te('modal.align.center', 'Mitte') ?></option><option value="right"><?= te('modal.align.right', 'Rechts') ?></option></select>
      </label>
      <label class="checkbox" data-f="bold"><input type="checkbox" id="mBold"> <?= te('modal.bold', 'Fett') ?></label>

      <div class="full table-editor" data-f="table">
        <div class="row spread">
          <strong style="font-size:14px;"><?= te('modal.table', 'Tabelle') ?></strong>
          <div class="row">
            <button type="button" class="btn small" id="tblAddRow"><?= te('modal.table.add_row', '+ Zeile') ?></button>
            <button type="button" class="btn small" id="tblAddCol"><?= te('modal.table.add_col', '+ Spalte') ?></button>
            <button type="button" class="btn small" id="tblDelRow"><?= te('modal.table.del_row', '− Zeile') ?></button>
            <button type="button" class="btn small" id="tblDelCol"><?= te('modal.table.del_col', '− Spalte') ?></button>
          </div>
        </div>
        <div id="tblGrid" class="table-grid"></div>
        <label class="field"><?= te('modal.table.paste', 'Aus Excel einfügen – hier hinein klicken und Strg+V') ?>
          <textarea id="tblPaste" class="paste-zone" placeholder="<?= te('modal.table.paste_placeholder', 'Zellen in Excel markieren, kopieren, hier einfügen…') ?>"></textarea>
        </label>
        <div class="form-grid">
          <label class="field"><?= te('modal.table.header_bg', 'Kopfzeile-Hintergrund') ?><span class="color-row"><input type="color" id="mHeaderBgPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mHeaderBg"></span></label>
          <label class="field"><?= te('modal.table.header_color', 'Kopfzeile-Farbe') ?><span class="color-row"><input type="color" id="mHeaderColorPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mHeaderColor"></span></label>
          <label class="field"><?= te('modal.table.cell_color', 'Zellen-Farbe') ?><span class="color-row"><input type="color" id="mCellColorPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mCellColor"></span></label>
          <label class="field"><?= te('modal.table.border_color', 'Rahmen-Farbe') ?><span class="color-row"><input type="color" id="mBorderColorPick" aria-label="<?= te('common.pick_color', 'Farbe wählen') ?>"><input type="text" id="mBorderColor"></span></label>
        </div>
      </div>

      <label class="field full" data-f="html"><?= te('modal.html', 'Animation – eigenes HTML/CSS (läuft isoliert in einer Sandbox)') ?>
        <textarea id="mHtml" class="paste-zone" style="min-height:170px;font-family:Consolas,monospace;font-size:12px;" placeholder="<?= te('modal.html_placeholder', 'HTML/CSS einfügen – Tipp: mit @keyframes animieren. Live-Vorschau über den „Vorschau“-Knopf bzw. auf dem Display.') ?>"></textarea>
      </label>

      <details class="full" data-f="advanced">
        <summary class="muted" style="cursor:pointer;"><?= te('modal.advanced', 'Position & Grösse') ?></summary>
        <div class="form-grid" style="margin-top:12px;">
          <label class="field">X<input type="number" id="mX" min="0" max="1900"></label>
          <label class="field">Y<input type="number" id="mY" min="0" max="1060"></label>
          <label class="field"><?= te('modal.width', 'Breite') ?><input type="number" id="mW" min="40" max="1920"></label>
          <label class="field"><?= te('modal.height', 'Höhe') ?><input type="number" id="mH" min="40" max="1080"></label>
        </div>
      </details>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn danger" id="deleteBlock"><?= te('modal.delete_block', 'Block entfernen') ?></button>
      <button type="button" class="btn primary" id="applyBlock"><?= te('modal.apply', 'Übernehmen') ?></button>
    </div>
  </div>
</div>

<!-- Live-Vorschau -->
<div id="previewOverlay" class="preview-overlay">
  <div class="row" style="width:min(1280px,92vw);justify-content:space-between;">
    <strong><?= te('editor.preview.title', 'Live-Vorschau (aktuelle Folie)') ?></strong>
    <button type="button" class="btn" id="closePreview"><?= te('editor.preview.close', '✕ Schliessen') ?></button>
  </div>
  <div class="preview-frame"><iframe id="previewFrame" title="<?= te('editor.preview.frame_title', 'Vorschau') ?>"></iframe></div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>window.SB_LANG = <?= json_encode((object) schauboard_translations_for_js(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="../assets/blocks.js?v=<?= htmlspecialchars($version['current'] ?? '0') ?>"></script>
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
  try { data = await res.json(); } catch (e) { throw new Error(sbT('api.unexpected_response', 'Unerwartete Server-Antwort.')); }
  if (!res.ok || !data || data.ok !== true) throw new Error(data && data.error ? data.error : sbT('common.save_failed', 'Speichern fehlgeschlagen.'));
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
  templates: JSON.parse(JSON.stringify(APP.templates || [])),
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

/* ===== Update-Hinweis: In-App-Update mit Fallback "gefuehrter Download" ===== */
(function initUpdateBanner() {
  const banner = document.getElementById('updateBanner');
  if (!banner) return;
  const txt = banner.querySelector('.ub-text');
  const apply = document.getElementById('ubApply');
  const dl = document.getElementById('ubDownload');
  const howtoBtn = document.getElementById('ubHowtoBtn');
  const howtoPanel = document.getElementById('ubHowtoPanel');
  const dismiss = document.getElementById('ubDismiss');

  howtoBtn.addEventListener('click', () => { howtoPanel.hidden = !howtoPanel.hidden; });

  function showDownloadFallback(info) {
    if (info.url) { dl.href = info.url; dl.hidden = false; }
  }

  fetch('../api/update_check.php', {headers: {'Accept': 'application/json'}})
    .then(r => r.json())
    .then(info => {
      if (!info || info.ok !== true || !info.update_available || !info.latest) return;
      // Pro Version nur einmal nerven: weggeklickte Version merken.
      let dismissed = '';
      try { dismissed = localStorage.getItem('sb_update_dismissed') || ''; } catch (e) {}
      if (dismissed === info.latest) return;

      txt.innerHTML = sbT('update.available', 'Update verfügbar: <strong>Schauboard v{version}</strong>', {version: esc(info.latest)})
        + (info.notes ? ' – ' + esc(info.notes) : '')
        + '<small>' + sbT('update.your_version', 'Deine Version: v{version}', {version: esc(info.current)})
        + (info.date ? ' · ' + sbT('update.published', 'veröffentlicht {date}', {date: esc(info.date)}) : '') + '</small>';

      dismiss.addEventListener('click', () => {
        banner.hidden = true;
        try { localStorage.setItem('sb_update_dismissed', info.latest); } catch (e) {}
      });

      if (info.can_auto_update) {
        apply.hidden = false;
        apply.addEventListener('click', async () => {
          if (!confirm(sbT('update.confirm', 'Jetzt auf Schauboard v{version} aktualisieren?\n\nDie Programmdateien werden ersetzt. Deine Folien, Einstellungen und Bilder bleiben erhalten.', {version: info.latest}))) return;
          apply.disabled = true; howtoBtn.disabled = true; dismiss.disabled = true;
          const before = txt.innerHTML;
          txt.innerHTML = sbT('update.installing', '⏳ Update wird installiert … bitte dieses Fenster offen lassen.');
          try {
            const res = await fetch('../api/update_apply.php', {method: 'POST', headers: {'Accept': 'application/json'}});
            const data = await res.json();
            if (!data || !data.ok) throw new Error(data && data.error ? data.error : sbT('update.failed', 'Update fehlgeschlagen.'));
            apply.hidden = true;
            txt.innerHTML = sbT('update.success', '✓ Erfolgreich auf <strong>v{version}</strong> aktualisiert – Seite lädt neu …', {version: esc(data.version)});
            setTimeout(() => location.reload(), 1800);
          } catch (e) {
            txt.innerHTML = before;
            apply.disabled = false; howtoBtn.disabled = false; dismiss.disabled = false;
            toast(e.message, 'err');
            showDownloadFallback(info); // manueller Weg als Rueckfall anbieten
          }
        });
      } else {
        showDownloadFallback(info);
      }

      banner.hidden = false;
    })
    .catch(() => {});
})();
</script>
</body>
</html>
