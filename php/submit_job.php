<?php
/**
 * NETXIA — Handler Portal de Talento
 * Guarda postulación en JSON + almacena CV + envía email
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
$rl_key = 'job_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_JOB)) {
    json_response(false, 'Límite de postulaciones alcanzado. Intenta en 1 hora.');
}

// Sanitizar campos
$nombre       = sanitize($_POST['nombre']       ?? '', 100);
$email        = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono     = sanitize($_POST['telefono']     ?? '', 20);
$cargo        = sanitize($_POST['cargo']        ?? '', 100);
$experiencia  = sanitize($_POST['experiencia']  ?? '', 50);
$linkedin     = sanitize($_POST['linkedin']     ?? '', 200);
$carta        = sanitize($_POST['carta']        ?? '', 3000);
$habilidades  = sanitize($_POST['habilidades']  ?? '', 500);

$errors = [];
if (empty($nombre))             $errors[] = 'Nombre requerido';
if (!$email)                    $errors[] = 'Email inválido';
if (empty($cargo))              $errors[] = 'Selecciona un cargo de interés';
if (mb_strlen($carta) < 30)    $errors[] = 'Carta de presentación muy corta (mín. 30 caracteres)';

if (!empty($errors)) {
    json_response(false, implode('. ', $errors));
}

// Procesar CV (si se subió)
$cv_filename = '';
if (!empty($_FILES['cv']['tmp_name'])) {
    $file    = $_FILES['cv'];
    $maxSize = MAX_CV_SIZE;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_response(false, 'Error al subir el CV. Verifica el archivo.');
    }
    if ($file['size'] > $maxSize) {
        json_response(false, 'CV demasiado grande. Máximo 2 MB.');
    }

    // Validar tipo MIME real
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_CV_TYPES)) {
        json_response(false, 'Formato de CV no permitido. Usa PDF o DOCX.');
    }

    // Nombre seguro
    $ext          = ($mimeType === 'application/pdf') ? 'pdf' : 'docx';
    $cv_filename  = date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '_', $nombre) . '.' . $ext;
    $uploadPath   = UPLOAD_DIR . '/' . $cv_filename;

    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        json_response(false, 'No se pudo guardar el CV. Intenta de nuevo.');
    }
}

// Guardar postulación en JSON
$record = [
    'id'          => uniqid('job_', true),
    'fecha'       => date('c'),
    'nombre'      => $nombre,
    'email'       => $email,
    'telefono'    => $telefono,
    'cargo'       => $cargo,
    'experiencia' => $experiencia,
    'linkedin'    => $linkedin,
    'habilidades' => $habilidades,
    'carta'       => $carta,
    'cv_archivo'  => $cv_filename,
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
];

$dir  = DATA_DIR . '/applications';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$file = $dir . '/' . date('Y-m') . '.json';
$existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$existing[] = $record;
@file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

log_event('jobs', "Nueva postulación: $nombre - $cargo - $email");

// Email si SMTP configurado
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
        $mail->addAddress(ADMIN_EMAIL, 'Netxia RRHH');
        $mail->addReplyTo($email, $nombre);

        if ($cv_filename && file_exists(UPLOAD_DIR . '/' . $cv_filename)) {
            $mail->addAttachment(UPLOAD_DIR . '/' . $cv_filename, "CV_$nombre.$ext");
        }

        $mail->isHTML(true);
        $mail->Subject = "👤 Nueva Postulación: $nombre — $cargo";
        $mail->Body    = "
        <h2>Nueva Postulación — Portal de Talento Netxia</h2>
        <table cellpadding='8' style='font-family:sans-serif;border-collapse:collapse'>
          <tr><td><b>Nombre:</b></td><td>$nombre</td></tr>
          <tr><td><b>Email:</b></td><td>$email</td></tr>
          <tr><td><b>Teléfono:</b></td><td>$telefono</td></tr>
          <tr><td><b>Cargo interés:</b></td><td>$cargo</td></tr>
          <tr><td><b>Experiencia:</b></td><td>$experiencia</td></tr>
          <tr><td><b>LinkedIn:</b></td><td>$linkedin</td></tr>
          <tr><td><b>Habilidades:</b></td><td>$habilidades</td></tr>
          <tr><td><b>Carta:</b></td><td>" . nl2br($carta) . "</td></tr>
        </table>";

        $mail->send();
    } catch (Exception $e) {
        log_event('jobs', 'Email error: ' . $e->getMessage(), 'WARN');
    }
}

unset($_SESSION['csrf_token']);
json_response(true, '¡Postulación recibida, ' . $nombre . '! Revisaremos tu perfil y te contactaremos pronto.');
