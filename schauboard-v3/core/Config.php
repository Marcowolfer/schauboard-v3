<?php

function schauboard_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'app_env' => getenv('SCHAUBOARD_ENV') ?: 'production',
        'admin_password_file' => dirname(__DIR__) . '/data/admin_password.php',
        'content_file' => dirname(__DIR__) . '/data/content.json',
        'templates_file' => dirname(__DIR__) . '/data/templates.json',
        'uploads_dir' => dirname(__DIR__) . '/uploads',
        // Update-Hinweis: Manifest auf der Projekt-Website. Leeren String setzen
        // (oder update_check_enabled=false) schaltet die Pruefung komplett ab.
        'update_check_enabled' => true,
        'update_manifest_url' => 'https://schauboard.ch/dl/latest.json',
    ];

    $localFile = dirname(__DIR__) . '/config.local.php';
    if (is_file($localFile)) {
        $localConfig = require $localFile;
        if (is_array($localConfig)) {
            $config = array_replace($config, $localConfig);
        }
    }

    return $config;
}
