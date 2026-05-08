<?php
$version ??= schauboard_version();
$authMode = !empty($needsSetup) ? 'setup' : 'login';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($version['name'] ?? 'Schauboard') . ' ' . ($authMode === 'setup' ? 'Setup' : 'Login')) ?></title>
<style>
:root{
  --bg:#08111d;
  --panel:#101b2d;
  --line:rgba(255,255,255,0.08);
  --text:#f5f7fb;
  --muted:#95a8c0;
  --accent:#5f8cff;
  --accent2:#73dfc4;
  --danger:#ff8f97;
}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px;color:var(--text);font-family:"Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 10% 0%, rgba(95,140,255,0.18), transparent 24%),radial-gradient(circle at 88% 8%, rgba(115,223,196,0.12), transparent 20%),linear-gradient(180deg,#08111d 0%,#0c1728 100%)}
.shell{width:min(560px,100%);padding:44px;border-radius:32px;border:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)),linear-gradient(180deg, rgba(11,18,31,0.96), rgba(7,12,23,0.94));box-shadow:0 36px 100px rgba(0,0,0,0.44)}
.badge{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;background:rgba(95,140,255,0.14);border:1px solid rgba(95,140,255,0.2);font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#d5e2ff;margin-bottom:16px}
.brand{font-size:42px;line-height:.94;letter-spacing:-.04em;margin-bottom:8px}
.subtitle{color:var(--muted);font-size:15px;line-height:1.6;margin-bottom:28px}
.grid{display:grid;gap:14px}
label{display:grid;gap:8px;color:var(--muted);font-size:13px;font-weight:600}
input{width:100%;min-height:52px;padding:14px 16px;border-radius:18px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--text);font-size:15px;outline:none;transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease}
input:focus{border-color:rgba(95,140,255,0.3);box-shadow:0 0 0 4px rgba(95,140,255,0.12);transform:translateY(-1px)}
button{min-height:52px;margin-top:6px;padding:14px 18px;border:none;border-radius:18px;background:linear-gradient(135deg, #5f8cff, #73dfc4);color:#071321;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 18px 34px rgba(95,140,255,0.24);transition:transform .18s ease, box-shadow .18s ease, filter .18s ease}
button:hover{transform:translateY(-1px);filter:saturate(1.05);box-shadow:0 22px 40px rgba(95,140,255,0.28)}
.error{margin-bottom:16px;padding:13px 14px;border-radius:16px;border:1px solid rgba(255,143,151,0.24);background:rgba(255,143,151,0.12);color:#ffc6cb;font-size:13px}
.hint{margin-top:20px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.08);color:var(--muted);font-size:12px;line-height:1.6}
</style>
</head>
<body>
<div class="shell">
  <div class="badge"><?= htmlspecialchars($version['label'] ?? 'v3.0.0') ?></div>
  <div class="brand">Schauboard v3</div>
  <?php if ($authMode === 'setup'): ?>
    <div class="subtitle">Beim ersten Start bitte ein Admin-Passwort festlegen. Das Passwort wird als Hash in einer Datei gespeichert.</div>
    <?php if (!empty($setupError)): ?><div class="error"><?= htmlspecialchars($setupError) ?></div><?php endif; ?>
    <form method="post" class="grid">
      <label>
        Neues Passwort
        <input type="password" name="setup_password" placeholder="Mindestens 8 Zeichen" autofocus>
      </label>
      <label>
        Passwort wiederholen
        <input type="password" name="setup_password_confirm" placeholder="Passwort bestaetigen">
      </label>
      <button type="submit">Passwort setzen</button>
    </form>
    <div class="hint">Wenn die Passwortdatei geloescht wird, startet dieses Setup erneut.</div>
  <?php else: ?>
    <div class="subtitle">Mit dem Admin-Passwort anmelden, um den Editor zu oeffnen.</div>
    <?php if (!empty($loginError)): ?><div class="error">Passwort ist nicht korrekt.</div><?php endif; ?>
    <form method="post" class="grid">
      <label>
        Passwort
        <input type="password" name="login_password" placeholder="Passwort eingeben" autofocus>
      </label>
      <button type="submit">Anmelden</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
