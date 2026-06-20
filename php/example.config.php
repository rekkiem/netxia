<?php
/**
 * NETXIA — Configuración Central v2.0 FINAL
 * SMTP: Gmail (funciona en 50webs Free + XAMPP local)
 *
 * ⚠️  ANTES DE SUBIR AL SERVIDOR:
 *  1. Genera tu App Password en: https://myaccount.google.com/apppasswords
 *     (Gmail → Seguridad → Verificación en 2 pasos → Contraseñas de aplicación)
 *  2. Pega los 16 caracteres en SMTP_PASS (sin espacios)
 */

// ─── Gmail SMTP ───────────────────────────────────────────────────────────────
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);                          // TLS
define('SMTP_USER',      'netxia.chile@gmail.com');     // Tu cuenta Gmail
define('SMTP_PASS',      '');                           // ← App Password de 16 chars
define('SMTP_FROM',      'netxia.chile@gmail.com');
define('SMTP_FROM_NAME', 'Netxia Consultores TI');
define('ADMIN_EMAIL',    'contacto@netxia.cl');         // Destino de los formularios

// ─── Gemini API + fallback local (Chatbot) ───────────────────────────────────
// Crea una API key gratuita en https://aistudio.google.com/app/apikey
// Si la key queda vacía, chatbot.php responde con FAQ local sin costo.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AQ.xxx'); // ← pega aquí tu AI Studio key si 50webs no soporta variables de entorno
define('GEMINI_MODEL',   getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite');
define('CHATBOT_MODEL',  GEMINI_MODEL); // compatibilidad con test_email.php y documentación previa
define('CHATBOT_LOCAL_FALLBACK', true);

// ─── Rate Limiting ────────────────────────────────────────────────────────────
define('RATE_LIMIT_CONTACT', 5);   // envíos/hora por IP
define('RATE_LIMIT_JOB',     3);   // postulaciones/hora por IP
define('RATE_LIMIT_CHAT',    30);  // mensajes/hora por IP

// ─── Rutas (filesystem, NO URLs) ─────────────────────────────────────────────
define('ROOT_DIR',    dirname(__DIR__));
define('DATA_DIR',    ROOT_DIR . '/data');
define('LOG_DIR',     ROOT_DIR . '/logs');
define('UPLOAD_DIR',  ROOT_DIR . '/uploads/cv');
define('SESSION_DIR', DATA_DIR . '/sessions');

// ─── Límites de archivos ──────────────────────────────────────────────────────
define('MAX_CV_SIZE',      2 * 1024 * 1024);  // 2 MB
define('ALLOWED_CV_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]);

// ─── Entorno (auto-detectado) ─────────────────────────────────────────────────
define('IS_LOCAL', in_array(
    $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '',
    ['localhost', '127.0.0.1', '::1']
));

// ═════════════════════════════════════════════════════════════════════════════
// HELPERS — no modificar
// ═════════════════════════════════════════════════════════════════════════════
function netxia_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $dir = SESSION_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_writable($dir)) session_save_path($dir);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => !IS_LOCAL && isset($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function sanitize(string $v, int $max = 500): string {
    return mb_substr(htmlspecialchars(strip_tags(str_replace(chr(0), '', trim($v))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 0, $max);
}

function json_response(bool $ok, string $msg, array $extra = []): never {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => $ok, 'message' => $msg, ...$extra]);
    exit;
}

function log_event(string $file, string $msg, string $lvl = 'INFO'): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);
    @file_put_contents(
        LOG_DIR . "/$file.log",
        sprintf("[%s] [%s] [%s] %s\n", date('Y-m-d H:i:s'), $lvl, $_SERVER['REMOTE_ADDR'] ?? '-', $msg),
        FILE_APPEND | LOCK_EX
    );
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
    netxia_session_start();
    if (empty($_SESSION['csrf_token'])) return false;
    if ((time() - ($_SESSION['csrf_time'] ?? 0)) > 3600) { unset($_SESSION['csrf_token']); return false; }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function rate_limit(string $key, int $max): bool {
    if (IS_LOCAL) return true;   // Sin límite en desarrollo local
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);
    $f    = LOG_DIR . '/rl_' . md5($key) . '.json';
    $now  = time();
    $data = file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
    $data = array_values(array_filter($data, fn($t) => ($now - $t) < 3600));
    if (count($data) >= $max) return false;
    $data[] = $now;
    @file_put_contents($f, json_encode($data), LOCK_EX);
    return true;
}

/**
 * Crea y devuelve una instancia de PHPMailer pre-configurada con Gmail SMTP.
 * Usada por submit_requirements.php, submit_job.php y test_email.php
 */
function create_mailer(): PHPMailer\PHPMailer\PHPMailer {
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    require_once __DIR__ . '/phpmailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;          // smtp.gmail.com
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;          // netxia.chile@gmail.com
    $mail->Password   = SMTP_PASS;          // App Password 16 chars
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;          // 587
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);

    // Gmail requiere que From == Username; OK ya que ambos son netxia.chile@gmail.com
    // En local: relajar verificación SSL
    if (IS_LOCAL) {
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]];
    }

    return $mail;
}
