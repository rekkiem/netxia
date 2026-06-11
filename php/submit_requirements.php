<?php
/**
 * NETXIA — submit_requirements.php v2.0
 * Gmail SMTP · CSRF · Rate limit · JSON storage
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
    if (!IS_LOCAL) json_response(false, 'Token inválido. Recarga la página e intenta de nuevo.');
    log_event('requirements', 'CSRF bypass (local)', 'WARN');
}

// Rate limit
if (!rate_limit('req_' . ($_SERVER['REMOTE_ADDR'] ?? ''), RATE_LIMIT_CONTACT))
    json_response(false, 'Demasiados envíos. Intenta en 1 hora.');

// Validar campos
$nombre      = sanitize($_POST['nombre']      ?? '', 100);
$empresa     = sanitize($_POST['empresa']     ?? '', 150);
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono    = sanitize($_POST['telefono']    ?? '', 20);
$detalle     = sanitize($_POST['detalle']     ?? '', 3000);
$presupuesto = sanitize($_POST['presupuesto'] ?? '', 50);
$urgencia    = sanitize($_POST['urgencia']    ?? '', 50);
$srvRaw      = sanitize($_POST['servicio']    ?? '', 50);
$servicio    = in_array($srvRaw, ['ia','ciberseguridad','cloud','otro','']) ? $srvRaw : 'otro';

$errs = [];
if (empty($nombre))           $errs[] = 'Nombre requerido';
if (empty($empresa))          $errs[] = 'Empresa requerida';
if (!$email)                  $errs[] = 'Email inválido';
if (mb_strlen($detalle) < 20) $errs[] = 'Detalla tu requerimiento (mín. 20 caracteres)';
if ($errs) json_response(false, implode('. ', $errs));

// Guardar en JSON
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
log_event('requirements', "Guardado: $empresa — $email");

// Enviar email vía Gmail SMTP
$emailNote = '';
if (empty(SMTP_PASS)) {
    $emailNote = IS_LOCAL ? ' [Dev: SMTP_PASS vacío — configura App Password]' : '';
    log_event('requirements', 'SMTP_PASS vacío', 'WARN');
} else {
    try {
        $mail = create_mailer();
        $mail->addAddress(ADMIN_EMAIL, 'Netxia');
        $mail->addReplyTo($email, $nombre);
        $mail->Subject = "🔔 Requerimiento: $empresa ($servicio)";
        $mail->isHTML(true);
        $mail->Body = "
<div style='font-family:Arial,sans-serif;max-width:600px;background:#06091A;color:#EEF2FF;padding:24px;border-radius:12px'>
  <h2 style='color:#00D2FF;margin-bottom:20px'>Nuevo Requerimiento de Cotización</h2>
  <table width='100%' cellpadding='8' style='border-collapse:collapse'>
    <tr style='border-bottom:1px solid #1a2040'><td width='140' style='color:#8B9DC3'>Nombre</td><td>" . htmlspecialchars($nombre) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Empresa</td><td>" . htmlspecialchars($empresa) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Email</td><td><a href='mailto:$email' style='color:#00D2FF'>$email</a></td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Teléfono</td><td>" . htmlspecialchars($telefono) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Servicio</td><td>" . htmlspecialchars($servicio) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Presupuesto</td><td>" . htmlspecialchars($presupuesto) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Urgencia</td><td>" . htmlspecialchars($urgencia) . "</td></tr>
    <tr><td style='color:#8B9DC3;vertical-align:top'>Detalle</td><td style='line-height:1.6'>" . nl2br(htmlspecialchars($detalle)) . "</td></tr>
  </table>
  <p style='margin-top:20px;color:#8B9DC3;font-size:12px'>Recibido: " . date('d/m/Y H:i:s') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "</p>
</div>";
        $mail->AltBody = "Requerimiento de $nombre ($empresa)\nEmail: $email\nServicio: $servicio\nDetalle: $detalle";
        $mail->send();
        log_event('requirements', "Email Gmail OK → $ADMIN_EMAIL");
    } catch (\Exception $e) {
        $err = $e->getMessage();
        log_event('requirements', "Gmail ERROR: $err", 'WARN');
        @file_put_contents(LOG_DIR . '/email_errors.log', date('c') . " | REQ | $err\n", FILE_APPEND | LOCK_EX);
        $emailNote = IS_LOCAL ? " [Dev: Error SMTP — $err]" : '';
    }
}

unset($_SESSION['csrf_token']);
json_response(true, '¡Requerimiento enviado' . $emailNote . '! Te contactaremos en menos de 24 horas hábiles.');
