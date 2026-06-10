<?php
/**
 * NETXIA — php/blog.php v1.2
 * Sirve data/blog.json de forma segura (PHP lee filesystem, no HTTP)
 * Fix: display_errors off, JSON siempre válido
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$file = DATA_DIR . '/blog.json';

if (!file_exists($file) || !is_readable($file)) {
    http_response_code(404);
    echo json_encode([]);
    exit;
}

$content = file_get_contents($file);
$decoded = json_decode($content);

if ($decoded === null) {
    http_response_code(500);
    echo json_encode([]);
    exit;
}

// Filtro opcional por categoría: ?cat=Ciberseguridad
$cat = isset($_GET['cat']) ? trim($_GET['cat']) : '';
if ($cat !== '' && is_array($decoded)) {
    $decoded = array_values(array_filter($decoded, fn($a) => ($a->categoria ?? '') === $cat));
}

echo json_encode($decoded);
