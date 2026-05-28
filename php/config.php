<?php
/**
 * NETXIA — Configuración Central
 * ⚠️  IMPORTANTE: Completa tus credenciales antes de desplegar
 */

// ─── SMTP / Email ───────────────────────────────────────────────────────────
define('SMTP_HOST',      getenv('SMTP_HOST') ?: 'mail.netxia.cl');
define('SMTP_PORT',      587);
define('SMTP_USER',      getenv('SMTP_USER') ?: 'contacto@netxia.cl');
define('SMTP_PASS',      getenv('SMTP_PASS') ?: '');          // ← Completa
define('SMTP_FROM',      'contacto@netxia.cl');
define('SMTP_FROM_NAME', 'Netxia Web');
define('ADMIN_EMAIL',    'contacto@netxia.cl');

// ─── Claude API (Chatbot) ────────────────────────────────────────────────────
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');  // ← sk-ant-...
define('CHATBOT_MODEL',     'claude-sonnet-4-20250514');

// ─── Rate Limiting ────────────────────────────────────────────────────────────
define('RATE_LIMIT_CONTACT',  5);   // envíos/hora por IP
define('RATE_LIMIT_JOB',      3);   // postulaciones/hora por IP
define('RATE_LIMIT_CHAT',     30);  // mensajes/hora por IP

// ─── Rutas ────────────────────────────────────────────────────────────────────
define('ROOT_DIR',        dirname(__DIR__));
define('DATA_DIR',        ROOT_DIR . '/data');
define('LOG_DIR',         ROOT_DIR . '/logs');
define('UPLOAD_DIR',      ROOT_DIR . '/uploads/cv');
define('SESSION_DIR',     DATA_DIR . '/sessions');

// ─── Limites de archivos ───────────────────────────────────────────────────────
define('MAX_CV_SIZE',     2 * 1024 * 1024);   // 2 MB (límite 50webs)
define('ALLOWED_CV_TYPES', ['application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

// ─── Funciones de Sesión (compatible 50webs) ─────────────────────────────────
function netxia_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $dir = SESSION_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (is_writable($dir)) session_save_path($dir);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function sanitize(string $input, int $maxLen = 500): string {
    $input = str_replace(chr(0), '', $input);
    $input = strip_tags($input);
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return mb_substr(trim($input), 0, $maxLen);
}

function json_response(bool $ok, string $message, array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => $ok, 'message' => $message, ...$data]);
    exit;
}

function log_event(string $file, string $msg, string $level = 'INFO'): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = sprintf("[%s] [%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $ip, $msg);
    @file_put_contents(LOG_DIR . '/' . $file . '.log', $line, FILE_APPEND | LOCK_EX);
}

function csrf_generate(): string {
    netxia_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time']  = time();
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    if ((time() - ($_SESSION['csrf_time'] ?? 0)) > 3600) {
        unset($_SESSION['csrf_token']);
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function rate_limit(string $key, int $max): bool {
    $file = LOG_DIR . '/rl_' . md5($key) . '.json';
    $now  = time();
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? [];
    }
    $data = array_filter($data, fn($t) => ($now - $t) < 3600);
    if (count($data) >= $max) return false;
    $data[] = $now;
    @file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    return true;
}
