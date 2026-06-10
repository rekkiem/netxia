<?php
/**
 * NETXIA — Handler Requerimientos v1.2
 * Fix: display_errors off, CSRF local bypass, JSON siempre válido
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);

require_once __DIR__ . '/config.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido.');
}

// ── CSRF ──────────────────────────────────────────────────────────────
$token = sanitize($_POST['csrf_token'] ?? '');
if (!csrf_validate($token)) {
    if (!IS_LOCAL) {
        json_response(false, 'Token de seguridad inválido. Recarga la página e intenta de nuevo.');
    }
    log_event('requirements', 'CSRF bypass en local (testing)', 'WARN');
}

// ── Rate limit ────────────────────────────────────────────────────────
$rl_key = 'req_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_CONTACT)) {
    json_response(false, 'Límite de envíos alcanzado. Intenta en 1 hora.');
}

// ── Sanitizar y validar ───────────────────────────────────────────────
$nombre      = sanitize($_POST['nombre']      ?? '', 100);
$empresa     = sanitize($_POST['empresa']     ?? '', 150);
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono    = sanitize($_POST['telefono']    ?? '', 20);
$detalle     = sanitize($_POST['detalle']     ?? '', 3000);
$presupuesto = sanitize($_POST['presupuesto'] ?? '', 50);
$urgencia    = sanitize($_POST['urgencia']    ?? '', 50);
$servicioRaw = sanitize($_POST['servicio']    ?? '', 50);

$servicioOk = ['ia', 'ciberseguridad', 'cloud', 'otro', ''];
$servicio   = in_array($servicioRaw, $servicioOk) ? $servicioRaw : 'otro';

$errors = [];
if (empty($nombre))           $errors[] = 'Nombre requerido';
if (empty($empresa))          $errors[] = 'Empresa requerida';
if (!$email)                  $errors[] = 'Email inválido';
if (mb_strlen($detalle) < 20) $errors[] = 'Describe tu requerimiento (mín. 20 caracteres)';

if (!empty($errors)) {
    json_response(false, implode('. ', $errors));
}

// ── Guardar en JSON ───────────────────────────────────────────────────
$record = [
    'id'          => uniqid('req_', true),
    'fecha'       => date('c'),
    'nombre'      => $nombre,
    'empresa'     => $empresa,
    'email'       => $email,
    'telefono'    => $telefono,
    'servicio'    => $servicio,
    'detalle'     => $detalle,
    'presupuesto' => $presupuesto,
    'urgencia'    => $urgencia,
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
];

$dir = DATA_DIR . '/requirements';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$jsonFile = $dir . '/' . date('Y-m') . '.json';
$existing = [];
if (file_exists($jsonFile)) {
    $raw = file_get_contents($jsonFile);
    $existing = json_decode($raw, true) ?? [];
}
$existing[] = $record;

if (!@file_put_contents($jsonFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
    log_event('requirements', "No se pudo escribir en: $jsonFile", 'WARN');
}

log_event('requirements', "Nuevo requerimiento: $empresa — $email");

// ── Email si SMTP configurado ─────────────────────────────────────────
if (!empty(SMTP_PASS)) {
    try {
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        require_once __DIR__ . '/phpmailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USER;
        $mail->Password    = SMTP_PASS;
        $mail->SMTPSecure  = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = SMTP_PORT;
        $mail->CharSet     = 'UTF-8';
        $mail->SMTPOptions = IS_LOCAL ? ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]] : [];

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL, 'Netxia');
        $mail->addReplyTo($email, $nombre);

        $mail->isHTML(true);
        $mail->Subject = "🔔 Nuevo Requerimiento: $empresa ($servicio)";
        $mail->Body    = "
        <h2 style='color:#00D2FF'>Nuevo Requerimiento de Cotización</h2>
        <table cellpadding='8' style='font-family:sans-serif'>
          <tr><td><b>Nombre:</b></td><td>" . htmlspecialchars($nombre) . "</td></tr>
          <tr><td><b>Empresa:</b></td><td>" . htmlspecialchars($empresa) . "</td></tr>
          <tr><td><b>Email:</b></td><td>" . htmlspecialchars($email) . "</td></tr>
          <tr><td><b>Teléfono:</b></td><td>" . htmlspecialchars($telefono) . "</td></tr>
          <tr><td><b>Servicio:</b></td><td>" . htmlspecialchars($servicio) . "</td></tr>
          <tr><td><b>Presupuesto:</b></td><td>" . htmlspecialchars($presupuesto) . "</td></tr>
          <tr><td><b>Urgencia:</b></td><td>" . htmlspecialchars($urgencia) . "</td></tr>
          <tr><td><b>Detalle:</b></td><td>" . nl2br(htmlspecialchars($detalle)) . "</td></tr>
        </table>";
        $mail->send();
    } catch (Exception $e) {
        log_event('requirements', 'Email error: ' . $e->getMessage(), 'WARN');
    }
}

unset($_SESSION['csrf_token']);
json_response(true, '¡Requerimiento enviado! Te contactaremos en menos de 24 horas hábiles.');
