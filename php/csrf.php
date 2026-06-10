<?php
/**
 * NETXIA — CSRF token endpoint v1.2
 * Fix: display_errors off, manejo robusto de sesión
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    netxia_session_start();
    $token = csrf_generate();
    echo json_encode(['token' => $token, 'ok' => true]);
} catch (Throwable $e) {
    // Si la sesión falla, devolver un token temporal (en local no bloquear)
    $fallback = bin2hex(random_bytes(16));
    echo json_encode(['token' => $fallback, 'ok' => false, 'warn' => 'session_failed']);
}
