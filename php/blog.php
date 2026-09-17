<?php
/**
 * NETXIA — Public blog index API
 * Serves data/blog.json without exposing the rest of /data/
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');

// Prefer config DATA_DIR; fallback to ../data for robustness
$dataDir = null;
$config = __DIR__ . '/config.php';
if (is_file($config)) {
    require_once $config;
    if (defined('DATA_DIR')) {
        $dataDir = DATA_DIR;
    }
}
if (!$dataDir) {
    $dataDir = dirname(__DIR__) . '/data';
}

$file = $dataDir . '/blog.json';
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    echo '[]';
    exit;
}

$raw = file_get_contents($file);
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo '[]';
    exit;
}

// Only published articles (default true if key missing)
$data = array_values(array_filter($data, static function ($a) {
    if (!is_array($a)) return false;
    return ($a['publicado'] ?? true) !== false;
}));

// Sort newest first
usort($data, static function ($a, $b) {
    return strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? ''));
});

$cat = trim((string)($_GET['cat'] ?? ''));
if ($cat !== '') {
    $data = array_values(array_filter($data, static function ($a) use ($cat) {
        return (($a['categoria'] ?? '') === $cat);
    }));
}

// Normalize URLs to absolute root paths
foreach ($data as &$a) {
    if (!empty($a['slug']) && (empty($a['url']) || str_starts_with((string)$a['url'], './'))) {
        $a['url'] = '/blog/' . $a['slug'] . '.html';
    }
}
unset($a);

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
