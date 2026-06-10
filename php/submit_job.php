<?php
/**
 * NETXIA — Handler Portal de Talento v1.2
 * Fix: display_errors off, finfo fallback, sesión robusta, JSON siempre válido
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
    // En local: advertir en lugar de bloquear para facilitar testing
    if (!IS_LOCAL) {
        json_response(false, 'Token de seguridad inválido. Recarga la página e intenta de nuevo.');
    }
    // IS_LOCAL: continúa con warning en log
    log_event('jobs', 'CSRF bypass en local (testing)', 'WARN');
}

// ── Rate limit ────────────────────────────────────────────────────────
$rl_key = 'job_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_JOB)) {
    json_response(false, 'Límite de postulaciones alcanzado. Intenta en 1 hora.');
}

// ── Sanitizar campos ──────────────────────────────────────────────────
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

if (!empty($errors)) {
    json_response(false, implode('. ', $errors));
}

// ── Procesar CV ───────────────────────────────────────────────────────
$cv_filename = '';
$ext         = '';

if (!empty($_FILES['cv']['tmp_name']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['cv'];

    if ($file['size'] > MAX_CV_SIZE) {
        json_response(false, 'CV demasiado grande. Máximo 2 MB.');
    }

    // Detectar tipo MIME — finfo primero, fallback a extensión
    $mimeType = '';
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file['tmp_name']);
    } else {
        // Fallback: inferir por extensión del nombre original
        $origExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = match($origExt) {
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    if (!in_array($mimeType, ALLOWED_CV_TYPES)) {
        json_response(false, "Formato de CV no permitido ($mimeType). Usa PDF o DOCX.");
    }

    $ext         = ($mimeType === 'application/pdf') ? 'pdf' : 'docx';
    $safeName    = preg_replace('/[^a-z0-9]/i', '_', $nombre);
    $cv_filename = date('Ymd_His') . '_' . $safeName . '.' . $ext;
    $uploadPath  = UPLOAD_DIR . '/' . $cv_filename;

    if (!is_dir(UPLOAD_DIR)) {
        if (!@mkdir(UPLOAD_DIR, 0755, true)) {
            log_event('jobs', 'No se pudo crear directorio de uploads: ' . UPLOAD_DIR, 'ERROR');
            json_response(false, 'Error interno al procesar el CV. Intenta sin adjuntar archivo.');
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        log_event('jobs', "No se pudo mover CV a: $uploadPath", 'ERROR');
        json_response(false, 'No se pudo guardar el CV. Intenta de nuevo o envía sin archivo.');
    }
} elseif (!empty($_FILES['cv']['tmp_name']) && $_FILES['cv']['error'] !== UPLOAD_ERR_NO_FILE) {
    // Error real de upload (no es simplemente que no se adjuntó nada)
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'El CV supera el límite del servidor (2 MB).',
        UPLOAD_ERR_FORM_SIZE  => 'El CV supera el límite del formulario.',
        UPLOAD_ERR_PARTIAL    => 'El CV se subió parcialmente. Intenta de nuevo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Error interno: sin directorio temporal.',
        UPLOAD_ERR_CANT_WRITE => 'Error interno: sin permisos de escritura.',
    ];
    $errCode = $_FILES['cv']['error'];
    json_response(false, $uploadErrors[$errCode] ?? "Error al subir CV (código $errCode).");
}

// ── Guardar en JSON ───────────────────────────────────────────────────
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

$dir = DATA_DIR . '/applications';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$jsonFile = $dir . '/' . date('Y-m') . '.json';
$existing = [];
if (file_exists($jsonFile)) {
    $raw = file_get_contents($jsonFile);
    $existing = json_decode($raw, true) ?? [];
}
$existing[] = $record;

if (!@file_put_contents($jsonFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
    log_event('jobs', "No se pudo escribir en: $jsonFile", 'WARN');
    // Continúa — el email aún puede enviarse
}

log_event('jobs', "Nueva postulación: $nombre — $cargo — $email");

// ── Enviar email si SMTP configurado ─────────────────────────────────
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
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = IS_LOCAL ? ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]] : [];

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL, 'Netxia RRHH');
        $mail->addReplyTo($email, $nombre);

        if ($cv_filename && file_exists(UPLOAD_DIR . '/' . $cv_filename)) {
            $mail->addAttachment(UPLOAD_DIR . '/' . $cv_filename, "CV_{$nombre}.{$ext}");
        }

        $mail->isHTML(true);
        $mail->Subject = "👤 Nueva Postulación: $nombre — $cargo";
        $mail->Body    = "
        <h2 style='color:#00D2FF'>Nueva Postulación — Netxia</h2>
        <table cellpadding='8' style='font-family:sans-serif'>
          <tr><td><b>Nombre:</b></td><td>" . htmlspecialchars($nombre) . "</td></tr>
          <tr><td><b>Email:</b></td><td>" . htmlspecialchars($email) . "</td></tr>
          <tr><td><b>Teléfono:</b></td><td>" . htmlspecialchars($telefono) . "</td></tr>
          <tr><td><b>Cargo:</b></td><td>" . htmlspecialchars($cargo) . "</td></tr>
          <tr><td><b>Experiencia:</b></td><td>" . htmlspecialchars($experiencia) . "</td></tr>
          <tr><td><b>Habilidades:</b></td><td>" . htmlspecialchars($habilidades) . "</td></tr>
          <tr><td><b>LinkedIn:</b></td><td>" . htmlspecialchars($linkedin) . "</td></tr>
          <tr><td><b>Carta:</b></td><td>" . nl2br(htmlspecialchars($carta)) . "</td></tr>
        </table>";
        $mail->send();
    } catch (Exception $e) {
        log_event('jobs', 'Email error: ' . $e->getMessage(), 'WARN');
        // No fallar — la postulación ya fue guardada en JSON
    }
}

unset($_SESSION['csrf_token']);
json_response(true, "¡Postulación recibida, {$nombre}! Revisaremos tu perfil y te contactaremos pronto.");
