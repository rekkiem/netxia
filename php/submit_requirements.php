<?php
/**
 * NETXIA — submit_requirements.php v1.3
 * Email feedback visible + error log dedicado
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);

require_once __DIR__ . '/config.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false, 'Método no permitido.');

// CSRF
$token = sanitize($_POST['csrf_token'] ?? '');
if (!csrf_validate($token)) {
    if (!IS_LOCAL) json_response(false, 'Token de seguridad inválido. Recarga la página e intenta de nuevo.');
    log_event('requirements', 'CSRF bypass local', 'WARN');
}

// Rate limit
if (!rate_limit('req_' . ($_SERVER['REMOTE_ADDR'] ?? ''), RATE_LIMIT_CONTACT))
    json_response(false, 'Límite de envíos alcanzado. Intenta en 1 hora.');

// Campos
$nombre      = sanitize($_POST['nombre']      ?? '', 100);
$empresa     = sanitize($_POST['empresa']     ?? '', 150);
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono    = sanitize($_POST['telefono']    ?? '', 20);
$detalle     = sanitize($_POST['detalle']     ?? '', 3000);
$presupuesto = sanitize($_POST['presupuesto'] ?? '', 50);
$urgencia    = sanitize($_POST['urgencia']    ?? '', 50);
$srvRaw      = sanitize($_POST['servicio']    ?? '', 50);
$servicio    = in_array($srvRaw, ['ia','ciberseguridad','cloud','otro','']) ? $srvRaw : 'otro';

$errors = [];
if (empty($nombre))           $errors[] = 'Nombre requerido';
if (empty($empresa))          $errors[] = 'Empresa requerida';
if (!$email)                  $errors[] = 'Email inválido';
if (mb_strlen($detalle) < 20) $errors[] = 'Describe tu requerimiento (mín. 20 caracteres)';
if (!empty($errors)) json_response(false, implode('. ', $errors));

// Guardar JSON
$record = [
    'id' => uniqid('req_', true), 'fecha' => date('c'),
    'nombre' => $nombre, 'empresa' => $empresa, 'email' => $email,
    'telefono' => $telefono, 'servicio' => $servicio, 'detalle' => $detalle,
    'presupuesto' => $presupuesto, 'urgencia' => $urgencia,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];
$dir = DATA_DIR . '/requirements';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$jf  = $dir . '/' . date('Y-m') . '.json';
$arr = file_exists($jf) ? (json_decode(file_get_contents($jf), true) ?? []) : [];
$arr[] = $record;
@file_put_contents($jf, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
log_event('requirements', "Requerimiento guardado: $empresa - $email");

// Email
$emailStatus = '';
if (empty(SMTP_PASS)) {
    $emailStatus = ' (⚠️ Email no enviado: SMTP_PASS vacío en config.php)';
    log_event('requirements', 'SMTP_PASS vacío — email no enviado', 'WARN');
} else {
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
        if (IS_LOCAL) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL, 'Netxia');
        $mail->addReplyTo($email, $nombre);
        $mail->isHTML(true);
        $mail->Subject = "🔔 Nuevo Requerimiento: $empresa ($servicio)";
        $mail->Body    = "<h2 style='color:#00D2FF'>Nuevo Requerimiento</h2>
            <table cellpadding='8' style='font-family:sans-serif'>
            <tr><td><b>Nombre:</b></td><td>".htmlspecialchars($nombre)."</td></tr>
            <tr><td><b>Empresa:</b></td><td>".htmlspecialchars($empresa)."</td></tr>
            <tr><td><b>Email:</b></td><td>".htmlspecialchars($email)."</td></tr>
            <tr><td><b>Teléfono:</b></td><td>".htmlspecialchars($telefono)."</td></tr>
            <tr><td><b>Servicio:</b></td><td>".htmlspecialchars($servicio)."</td></tr>
            <tr><td><b>Presupuesto:</b></td><td>".htmlspecialchars($presupuesto)."</td></tr>
            <tr><td><b>Urgencia:</b></td><td>".htmlspecialchars($urgencia)."</td></tr>
            <tr><td><b>Detalle:</b></td><td>".nl2br(htmlspecialchars($detalle))."</td></tr>
            </table>";
        $mail->send();
        $emailStatus = ' Email enviado a ' . ADMIN_EMAIL . '.';
        log_event('requirements', "Email enviado OK a $ADMIN_EMAIL");
    } catch (\Exception $e) {
        $err = $e->getMessage();
        log_event('requirements', "Email ERROR: $err", 'WARN');
        @file_put_contents(LOG_DIR . '/email_errors.log', date('c') . " | REQ | $err\n", FILE_APPEND | LOCK_EX);
        $emailStatus = ' (Email no enviado: ' . $err . ')';
    }
}

unset($_SESSION['csrf_token']);
json_response(true, '¡Requerimiento enviado!' . (IS_LOCAL ? $emailStatus : '') . ' Te contactaremos en menos de 24 horas hábiles.');
