<?php
/**
 * NETXIA — submit_job.php v1.3
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
    if (!IS_LOCAL) json_response(false, 'Token de seguridad inválido. Recarga la página.');
    log_event('jobs', 'CSRF bypass local', 'WARN');
}

// Rate limit
if (!rate_limit('job_' . ($_SERVER['REMOTE_ADDR'] ?? ''), RATE_LIMIT_JOB))
    json_response(false, 'Límite de postulaciones alcanzado. Intenta en 1 hora.');

// Campos
$nombre      = sanitize($_POST['nombre']      ?? '', 100);
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono    = sanitize($_POST['telefono']    ?? '', 20);
$cargo       = sanitize($_POST['cargo']       ?? '', 100);
$experiencia = sanitize($_POST['experiencia'] ?? '', 50);
$linkedin    = sanitize($_POST['linkedin']    ?? '', 200);
$carta       = sanitize($_POST['carta']       ?? '', 3000);
$habilidades = sanitize($_POST['habilidades'] ?? '', 500);

$errors = [];
if (empty($nombre))          $errors[] = 'Nombre requerido';
if (!$email)                 $errors[] = 'Email inválido';
if (empty($cargo))           $errors[] = 'Cargo requerido';
if (mb_strlen($carta) < 30) $errors[] = 'Carta muy corta (mín. 30 caracteres)';
if (!empty($errors)) json_response(false, implode('. ', $errors));

// CV
$cv_filename = '';
$ext = '';
if (!empty($_FILES['cv']['tmp_name']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['cv'];
    if ($file['size'] > MAX_CV_SIZE) json_response(false, 'CV demasiado grande (máx 2 MB).');

    $mimeType = '';
    if (class_exists('finfo')) {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file['tmp_name']);
    } else {
        $origExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = match($origExt) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
    if (!in_array($mimeType, ALLOWED_CV_TYPES)) json_response(false, "Formato no permitido. Usa PDF o DOCX.");

    $ext = ($mimeType === 'application/pdf') ? 'pdf' : 'docx';
    $cv_filename = date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '_', $nombre) . '.' . $ext;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $cv_filename))
        json_response(false, 'No se pudo guardar el CV. Verifica permisos de uploads/cv/');
}

// Guardar JSON
$record = [
    'id' => uniqid('job_', true), 'fecha' => date('c'),
    'nombre' => $nombre, 'email' => $email, 'telefono' => $telefono,
    'cargo' => $cargo, 'experiencia' => $experiencia, 'linkedin' => $linkedin,
    'habilidades' => $habilidades, 'carta' => $carta, 'cv_archivo' => $cv_filename,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];
$dir = DATA_DIR . '/applications';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$jf  = $dir . '/' . date('Y-m') . '.json';
$arr = file_exists($jf) ? (json_decode(file_get_contents($jf), true) ?? []) : [];
$arr[] = $record;
@file_put_contents($jf, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
log_event('jobs', "Postulación guardada: $nombre — $cargo — $email");

// Email
$emailStatus = '';
if (empty(SMTP_PASS)) {
    $emailStatus = ' (⚠️ Email no enviado: SMTP_PASS vacío en config.php)';
    log_event('jobs', 'SMTP_PASS vacío — email no enviado', 'WARN');
} else {
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
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        if (IS_LOCAL) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL, 'Netxia RRHH');
        $mail->addReplyTo($email, $nombre);
        if ($cv_filename && file_exists(UPLOAD_DIR . '/' . $cv_filename))
            $mail->addAttachment(UPLOAD_DIR . '/' . $cv_filename, "CV_{$nombre}.{$ext}");

        $mail->isHTML(true);
        $mail->Subject = "👤 Nueva Postulación: $nombre — $cargo";
        $mail->Body    = "<h2 style='color:#00D2FF'>Nueva Postulación</h2>
            <table cellpadding='8' style='font-family:sans-serif'>
            <tr><td><b>Nombre:</b></td><td>".htmlspecialchars($nombre)."</td></tr>
            <tr><td><b>Email:</b></td><td>".htmlspecialchars($email)."</td></tr>
            <tr><td><b>Teléfono:</b></td><td>".htmlspecialchars($telefono)."</td></tr>
            <tr><td><b>Cargo:</b></td><td>".htmlspecialchars($cargo)."</td></tr>
            <tr><td><b>Experiencia:</b></td><td>".htmlspecialchars($experiencia)."</td></tr>
            <tr><td><b>Habilidades:</b></td><td>".htmlspecialchars($habilidades)."</td></tr>
            <tr><td><b>LinkedIn:</b></td><td>".htmlspecialchars($linkedin)."</td></tr>
            <tr><td><b>Carta:</b></td><td>".nl2br(htmlspecialchars($carta))."</td></tr>
            </table>";
        $mail->send();
        $emailStatus = ' Email enviado a ' . ADMIN_EMAIL . '.';
        log_event('jobs', "Email enviado OK a $ADMIN_EMAIL");
    } catch (\Exception $e) {
        $err = $e->getMessage();
        log_event('jobs', "Email ERROR: $err", 'WARN');
        @file_put_contents(LOG_DIR . '/email_errors.log', date('c') . " | JOB | $err\n", FILE_APPEND | LOCK_EX);
        $emailStatus = ' (Email no enviado: ' . $err . ')';
    }
}

unset($_SESSION['csrf_token']);
$msg = "¡Postulación recibida, {$nombre}!" . (IS_LOCAL ? $emailStatus : '') . ' Revisaremos tu perfil y te contactaremos pronto.';
json_response(true, $msg);
