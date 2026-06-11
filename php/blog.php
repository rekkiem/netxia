<?php
ini_set('display_errors', '0');
error_reporting(E_ERROR);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$file = DATA_DIR . '/blog.json';
if (!file_exists($file)) { http_response_code(404); echo json_encode([]); exit; }
$data = json_decode(file_get_contents($file));
if (!$data) { http_response_code(500); echo json_encode([]); exit; }
$cat = trim($_GET['cat'] ?? '');
if ($cat) $data = array_values(array_filter($data, fn($a) => ($a->categoria ?? '') === $cat));
echo json_encode($data);
