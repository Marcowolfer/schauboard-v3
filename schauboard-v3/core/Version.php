<?php

function schauboard_version(): array
{
    static $meta = null;

    if ($meta !== null) {
        return $meta;
    }

    $meta = require dirname(__DIR__) . '/version.php';
    $meta['label'] = 'v' . ($meta['current'] ?? '3.0.0');

    return $meta;
}
