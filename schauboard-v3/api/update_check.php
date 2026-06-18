<?php
// Update-Pruefung fuer den Admin: vergleicht die lokale Version mit dem Manifest
// auf der Projekt-Website. Nur Hinweis – es wird nichts ueberschrieben.
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_require_admin_session();
header('Content-Type: application/json; charset=UTF-8');

$force = isset($_GET['force']) && $_GET['force'] === '1';
$info = schauboard_check_update($force);

echo json_encode(['ok' => true] + $info);
