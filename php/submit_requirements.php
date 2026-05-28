<?php
/**
 * NETXIA — Handler Formulario de Requerimientos
 * Guarda en JSON + envía email via PHPMailer
 */
require_once __DIR__ . '/config.php';
netxia_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido.');
}

// CSRF
$token = sanitize($_POST['csrf_token'] ?? '');
if (!csrf_validate($token)) {
    json_response(false, 'Token de seguridad inválido. Recarga la página.');
}

// Rate limit
$rl_key = 'req_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_CONTACT)) {
    json_response(false, 'Límite de envíos alcanzado. Intenta en 1 hora.');
}

// Sanitizar y validar campos
$nombre   = sanitize($_POST['nombre']   ?? '', 100);
$empresa  = sanitize($_POST['empresa']  ?? '', 150);
$email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono = sanitize($_POST['telefono'] ?? '', 20);
$servicio = sanitize($_POST['servicio'] ?? '', 100);
$detalle  = sanitize($_POST['detalle']  ?? '', 3000);
$presupuesto = sanitize($_POST['presupuesto'] ?? '', 50);
$urgencia    = sanitize($_POST['urgencia']    ?? '', 50);

$errors = [];
if (empty($nombre))              $errors[] = 'Nombre requerido';
if (empty($empresa))             $errors[] = 'Empresa requerida';
if (!$email)                     $errors[] = 'Email inválido';
if (mb_strlen($detalle) < 20)    $errors[] = 'Describe tu requerimiento (mín. 20 caracteres)';
if (!in_array($servicio, ['ia','ciberseguridad','cloud','otro',''])) {
    $servicio = 'otro';
}

if (!empty($errors)) {
    json_response(false, implode('. ', $errors));
}

// Guardar en JSON
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
$file = $dir . '/' . date('Y-m') . '.json';
$existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$existing[] = $record;
@file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

log_event('requirements', "Nuevo requerimiento: $empresa - $email");

// Enviar email si SMTP configurado
if (!empty(SMTP_PASS)) {
    try {
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        require_once __DIR__ . '/phpmailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL, 'Netxia');
        $mail->addReplyTo($email, $nombre);

        $mail->isHTML(true);
        $mail->Subject = "🔔 Nuevo Requerimiento: $empresa ({$servicio})";
        $mail->Body    = "
        <h2>Nuevo Requerimiento de Cotización</h2>
        <table cellpadding='8' style='font-family:sans-serif;border-collapse:collapse'>
          <tr><td><b>Nombre:</b></td><td>$nombre</td></tr>
          <tr><td><b>Empresa:</b></td><td>$empresa</td></tr>
          <tr><td><b>Email:</b></td><td>$email</td></tr>
          <tr><td><b>Teléfono:</b></td><td>$telefono</td></tr>
          <tr><td><b>Servicio:</b></td><td>$servicio</td></tr>
          <tr><td><b>Presupuesto:</b></td><td>$presupuesto</td></tr>
          <tr><td><b>Urgencia:</b></td><td>$urgencia</td></tr>
          <tr><td><b>Detalle:</b></td><td>" . nl2br($detalle) . "</td></tr>
        </table>";

        $mail->send();
    } catch (Exception $e) {
        log_event('requirements', 'Email error: ' . $e->getMessage(), 'WARN');
    }
}

// Invalidar token CSRF
unset($_SESSION['csrf_token']);

json_response(true, '¡Requerimiento enviado! Te contactaremos en menos de 24 horas hábiles.');
