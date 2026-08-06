# Schauboard — installation

This folder *is* the application. Copy its contents into a web-accessible directory and open
`/admin/` in a browser — you will be asked to set an admin password on first visit.

- **Editor:** `https://your-server/admin/`
- **Display:** `https://your-server/?display=default` (open in fullscreen/kiosk mode on the TV)

## Requirements

- PHP 8.0 or newer (tested on 8.2–8.5)
- `allow_url_fopen=On` for the weather and RSS modules
- Write access for PHP to `data/` and `uploads/`
- PHP `zip` extension for one-click updates (optional)

## Keep `data/` private

It holds your content and the password hash. On Apache a protective `data/.htaccess` is created
automatically. On nginx, add:

```nginx
location ^~ /data/ { deny all; return 404; }
```

## Updating

Newer versions are offered in the admin area and can be installed with one click (the package is
verified via SHA-256, the old files are backed up and restored automatically if anything fails).
`data/`, `uploads/` and `config.local.php` are never touched — you can also just copy the new files
over an existing installation.

## More

Full documentation, screenshots and the project overview are in the
[repository README](../README.md) · [German handbook (PDF)](https://schauboard.ch/dl/doku/signage-doku.pdf) ·
[schauboard.ch](https://schauboard.ch)

Licensed under the Apache License 2.0 — see [LICENSE](../LICENSE).
