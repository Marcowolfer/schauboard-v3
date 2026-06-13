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
