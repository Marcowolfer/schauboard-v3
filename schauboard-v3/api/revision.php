<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

echo json_encode(['ok' => true, 'revision' => schauboard_revision()]);
