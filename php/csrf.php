<?php
require_once __DIR__ . '/config.php';
netxia_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['token' => csrf_generate()]);
