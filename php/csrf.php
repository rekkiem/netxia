<?php
ini_set('display_errors', '0');
error_reporting(E_ERROR);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    netxia_session_start();
    echo json_encode(['token' => csrf_generate(), 'ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['token' => bin2hex(random_bytes(16)), 'ok' => false]);
}
