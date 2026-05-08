<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

echo json_encode([
    'ok' => true,
    'app' => schauboard_version(),
    'time' => date('c'),
]);
