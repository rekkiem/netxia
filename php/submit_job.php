<?php
/**
 * NETXIA — submit_job.php v3
 * Persist application first → notify (Gmail API cascade) → status on lead
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido.');
}

$token = sanitize($_POST['csrf_token'] ?? '');
if (!csrf_validate($token)) {
    if (!IS_LOCAL) {
        json_response(false, 'Token inválido. Recarga la página.');
    }
    log_event('jobs', 'CSRF bypass (local)', 'WARN');
}

if (!rate_limit('job_' . ($_SERVER['REMOTE_ADDR'] ?? ''), RATE_LIMIT_JOB)) {
    json_response(false, 'Límite alcanzado. Intenta en 1 hora.');
}

$nombre      = sanitize($_POST['nombre']      ?? '', 100);
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono    = sanitize($_POST['telefono']    ?? '', 20);
$cargo       = sanitize($_POST['cargo']       ?? '', 100);
$experiencia = sanitize($_POST['experiencia'] ?? '', 50);
$linkedinRaw = trim((string)($_POST['linkedin'] ?? ''));
$linkedin    = $linkedinRaw !== '' ? filter_var($linkedinRaw, FILTER_VALIDATE_URL) : '';
$linkedin    = $linkedin ? sanitize($linkedin, 200) : '';
$carta       = sanitize($_POST['carta']       ?? '', 3000);
$habilidades = sanitize($_POST['habilidades'] ?? '', 500);

$errs = [];
if ($nombre === '') { $errs[] = 'Nombre requerido'; }
if (!$email) { $errs[] = 'Email inválido'; }
if ($cargo === '') { $errs[] = 'Cargo requerido'; }
if ($linkedinRaw !== '' && !$linkedin) { $errs[] = 'LinkedIn inválido'; }
if (mb_strlen($carta) < 30) { $errs[] = 'Carta muy corta (mín. 30 caracteres)'; }
if ($errs) {
    json_response(false, implode('. ', $errs));
}

$cv_filename = '';
$ext = '';
if (!empty($_FILES['cv']['tmp_name']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['cv'];
    if ($file['size'] > MAX_CV_SIZE) {
        json_response(false, 'CV demasiado grande (máx 2 MB).');
    }
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    } else {
        $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = match ($origExt) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
            default => 'application/octet-stream',
        };
    }
    if (!in_array($mime, ALLOWED_CV_TYPES, true)) {
        json_response(false, 'Solo se aceptan PDF o DOCX.');
    }
    $extMap = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $ext         = $extMap[$mime] ?? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName    = preg_replace('/[^a-z0-9]/i', '_', $nombre);
    $cv_filename = date('Ymd_His') . '_' . $safeName . '.' . $ext;
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $cv_filename)) {
        json_response(false, 'Error al guardar CV. Verifica permisos de uploads/cv/');
    }
} elseif (!empty($_FILES['cv']['error']) && $_FILES['cv']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadErrors = [2 => 'CV muy grande (máx 2 MB)', 3 => 'Upload incompleto. Intenta de nuevo.'];
    json_response(false, $uploadErrors[$_FILES['cv']['error']] ?? 'Error al subir CV.');
}

$record = [
    'id'             => uniqid('job_', true),
    'fecha'          => date('c'),
    'nombre'         => $nombre,
    'email'          => $email,
    'telefono'       => $telefono,
    'cargo'          => $cargo,
    'experiencia'    => $experiencia,
    'linkedin'       => $linkedin,
    'habilidades'    => $habilidades,
    'carta'          => $carta,
    'cv_archivo'     => $cv_filename,
    'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
    'notificado'     => false,
    'notif_via'      => null,
    'notif_error'    => null,
    'notif_at'       => null,
    'notif_attempts' => 0,
];

$dir = DATA_DIR . '/applications';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$jf  = $dir . '/' . date('Y-m') . '.json';
$arr = is_file($jf) ? (json_decode((string)file_get_contents($jf), true) ?? []) : [];
$arr[] = $record;
@file_put_contents($jf, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
log_event('jobs', "Guardado: $nombre — $cargo — $email — {$record['id']}");

$via = null;
$sendErrors = null;
$sent = false;

if (empty(SMTP_PASS) && !gmail_api_configured()) {
    log_event('jobs', 'Sin SMTP_PASS ni Gmail API — postulación guardada sin notificar', 'WARN');
    lead_set_notification('applications', $record['id'], false, null, 'sin_transporte');
} else {
    try {
        $mail = create_mailer();
        netxia_add_admin_recipients($mail, 'Netxia RRHH');
        $mail->addReplyTo($email, $nombre);
        if ($cv_filename && is_file(UPLOAD_DIR . '/' . $cv_filename)) {
            $mail->addAttachment(UPLOAD_DIR . '/' . $cv_filename, "CV_{$nombre}.{$ext}");
        }
        $mail->Subject = "👤 Postulación: $nombre — $cargo";
        $mail->isHTML(true);
        $mail->Body = "
<div style='font-family:Arial,sans-serif;max-width:600px;background:#06091A;color:#EEF2FF;padding:24px;border-radius:12px'>
  <h2 style='color:#00D2FF;margin-bottom:20px'>Nueva Postulación — Netxia</h2>
  <table width='100%' cellpadding='8' style='border-collapse:collapse'>
    <tr style='border-bottom:1px solid #1a2040'><td width='140' style='color:#8B9DC3'>Nombre</td><td>" . htmlspecialchars($nombre) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Email</td><td><a href='mailto:$email' style='color:#00D2FF'>$email</a></td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Teléfono</td><td>" . htmlspecialchars($telefono) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Cargo</td><td><strong style='color:#00E887'>" . htmlspecialchars($cargo) . "</strong></td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Experiencia</td><td>" . htmlspecialchars($experiencia) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>Habilidades</td><td>" . htmlspecialchars($habilidades) . "</td></tr>
    <tr style='border-bottom:1px solid #1a2040'><td style='color:#8B9DC3'>LinkedIn</td><td>" . ($linkedin ? "<a href='" . htmlspecialchars($linkedin) . "' style='color:#00D2FF'>" . htmlspecialchars($linkedin) . "</a>" : '—') . "</td></tr>
    <tr><td style='color:#8B9DC3;vertical-align:top'>Carta</td><td style='line-height:1.6'>" . nl2br(htmlspecialchars($carta)) . "</td></tr>
  </table>
  <p style='margin-top:16px;color:#8B9DC3;font-size:12px'>ID: {$record['id']} | " . ($cv_filename ? "CV: $cv_filename" : 'Sin CV') . " | " . date('d/m/Y H:i:s') . "</p>
</div>";
        $mail->AltBody = "Postulación de $nombre para $cargo\nEmail: $email\nCarta: $carta\nID: {$record['id']}";
        $sent = netxia_send($mail, 'jobs', $via, $sendErrors);
        lead_set_notification('applications', $record['id'], $sent, $via, $sent ? null : $sendErrors);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
        log_event('jobs', "Notify ERROR: $err", 'WARN');
        @file_put_contents(LOG_DIR . '/email_errors.log', date('c') . " | JOB | $err\n", FILE_APPEND | LOCK_EX);
        lead_set_notification('applications', $record['id'], false, null, $err);
        $sendErrors = $err;
    }
}

unset($_SESSION['csrf_token']);

if ($sent) {
    json_response(true, "¡Postulación recibida, {$nombre}! Revisaremos tu perfil y te contactaremos pronto.", [
        'lead_id' => $record['id'], 'notificado' => true, 'via' => $via,
    ]);
}

$msg = "¡Postulación recibida, {$nombre}! Quedó registrada.";
if (IS_LOCAL && $sendErrors) {
    $msg .= " [Dev: correo no notificado — $sendErrors]";
}
json_response(true, $msg, [
    'lead_id' => $record['id'], 'notificado' => false, 'via' => null,
]);
