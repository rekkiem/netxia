<!DOCTYPE html>
<?php
/**
 * NETXIA — php/test_email.php
 * Diagnóstico de SMTP. ELIMINAR después de verificar en producción.
 * Acceso: http://localhost/netxia/php/test_email.php
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/config.php';

$result  = null;
$sending = isset($_GET['send']);

if ($sending) {
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    require_once __DIR__ . '/phpmailer/Exception.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug  = 3; // Máximo debug
        $mail->Debugoutput = function($str, $level) use (&$debugLog) {
            $debugLog[] = htmlspecialchars($str);
        };

        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // En local: no verificar SSL (evita errores de certificado)
        if (IS_LOCAL) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL);
        $mail->isHTML(true);
        $mail->Subject = '✅ Test SMTP Netxia — ' . date('H:i:s');
        $mail->Body    = '<h2 style="color:#00D2FF">¡SMTP funcionando correctamente!</h2>
                          <p>Este email fue enviado desde el diagnóstico de Netxia.</p>
                          <p><b>Fecha:</b> ' . date('Y-m-d H:i:s') . '</p>
                          <p><b>Servidor:</b> ' . SMTP_HOST . ':' . SMTP_PORT . '</p>';

        $mail->send();
        $result = ['ok' => true, 'msg' => '✅ Email enviado correctamente a ' . ADMIN_EMAIL];
    } catch (\Exception $e) {
        $result = ['ok' => false, 'msg' => '❌ Error: ' . $e->getMessage()];
    }
}

// Verificar estado de configuración
$checks = [
    'SMTP_HOST configurado'    => !empty(SMTP_HOST) && SMTP_HOST !== 'mail.netxia.cl',
    'SMTP_USER configurado'    => !empty(SMTP_USER),
    'SMTP_PASS configurado'    => !empty(SMTP_PASS),
    'ADMIN_EMAIL configurado'  => !empty(ADMIN_EMAIL),
    'ANTHROPIC_API_KEY set'    => !empty(ANTHROPIC_API_KEY),
    'curl habilitado'          => function_exists('curl_init'),
    'finfo disponible'         => class_exists('finfo'),
    'Directorio data/'         => is_writable(DATA_DIR),
    'Directorio logs/'         => is_writable(LOG_DIR) || is_dir(LOG_DIR),
    'Directorio uploads/cv/'   => is_dir(UPLOAD_DIR),
    'IS_LOCAL'                 => IS_LOCAL,
];
?>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnóstico SMTP — Netxia</title>
<style>
  body { font-family: monospace; background:#06091A; color:#EEF2FF; padding:2rem; }
  h1   { color:#00D2FF; margin-bottom:1.5rem; }
  h2   { color:#00E887; margin:1.5rem 0 0.75rem; font-size:1rem; text-transform:uppercase; letter-spacing:.1em; }
  .check { display:flex; gap:1rem; padding:.4rem 0; border-bottom:1px solid rgba(255,255,255,.06); }
  .ok   { color:#00E887; }
  .fail { color:#FF3864; }
  .warn { color:#FFB800; }
  .box  { background:#0D1230; border:1px solid rgba(0,210,255,.2); border-radius:12px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; }
  pre   { background:#080C20; border-radius:8px; padding:1rem; overflow-x:auto; font-size:.8rem; color:#8B9DC3; max-height:300px; overflow-y:auto; }
  a.btn { display:inline-block; padding:.7rem 1.5rem; background:linear-gradient(135deg,#00D2FF,#00E887); color:#06091A; font-weight:700; border-radius:10px; text-decoration:none; margin-top:1rem; }
  .result-ok   { background:rgba(0,232,135,.1); border:1px solid rgba(0,232,135,.4); border-radius:10px; padding:1rem; color:#00E887; margin-bottom:1rem; }
  .result-fail { background:rgba(255,56,100,.1); border:1px solid rgba(255,56,100,.4); border-radius:10px; padding:1rem; color:#FF8AA7; margin-bottom:1rem; }
  .warn-box { background:rgba(255,184,0,.08); border:1px solid rgba(255,184,0,.3); border-radius:10px; padding:1rem; color:#FFB800; margin-bottom:1rem; }
</style>
</head>
<body>
<h1>🔧 Diagnóstico SMTP — Netxia</h1>

<?php if ($result): ?>
  <div class="<?= $result['ok'] ? 'result-ok' : 'result-fail' ?>">
    <?= $result['msg'] ?>
  </div>
  <?php if (!empty($debugLog)): ?>
  <h2>Log SMTP detallado</h2>
  <pre><?= implode("\n", $debugLog ?? []) ?></pre>
  <?php endif; ?>
<?php endif; ?>

<div class="box">
  <h2>Estado de configuración</h2>
  <?php foreach ($checks as $label => $ok): ?>
    <?php $val = is_bool($ok) ? $ok : (bool)$ok; ?>
    <div class="check">
      <span class="<?= $val ? 'ok' : 'fail' ?>"><?= $val ? '✅' : '❌' ?></span>
      <span><?= $label ?></span>
      <?php if (!$val && $label === 'SMTP_HOST configurado'): ?>
        <span class="warn"> ← Parece que es el valor por defecto. Verifica config.php</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="box">
  <h2>Valores actuales (sin contraseña)</h2>
  <div class="check"><span>SMTP_HOST:</span><span><?= htmlspecialchars(SMTP_HOST) ?></span></div>
  <div class="check"><span>SMTP_PORT:</span><span><?= SMTP_PORT ?></span></div>
  <div class="check"><span>SMTP_USER:</span><span><?= htmlspecialchars(SMTP_USER) ?></span></div>
  <div class="check"><span>SMTP_PASS:</span><span><?= empty(SMTP_PASS) ? '<span class="fail">⚠️ VACÍO — el email NO se enviará</span>' : '<span class="ok">✅ Configurado (' . strlen(SMTP_PASS) . ' chars)</span>' ?></span></div>
  <div class="check"><span>ADMIN_EMAIL:</span><span><?= htmlspecialchars(ADMIN_EMAIL) ?></span></div>
  <div class="check"><span>ANTHROPIC_API_KEY:</span><span><?= empty(ANTHROPIC_API_KEY) ? '<span class="fail">⚠️ VACÍO</span>' : '<span class="ok">✅ Configurado (sk-ant-...)</span>' ?></span></div>
  <div class="check"><span>CHATBOT_MODEL:</span><span><?= htmlspecialchars(CHATBOT_MODEL) ?></span></div>
</div>

<?php if (empty(SMTP_PASS)): ?>
<div class="warn-box">
  ⚠️ <strong>SMTP_PASS está vacío.</strong> El formulario mostrará "enviado" pero NO llegará ningún email.
  Edita <code>php/config.php</code> y completa <code>define('SMTP_PASS', 'tu_contraseña');</code>
</div>
<?php else: ?>
<div class="box">
  <h2>Probar envío de email</h2>
  <p style="color:#8B9DC3;margin-bottom:1rem">Enviará un email de prueba a <strong><?= htmlspecialchars(ADMIN_EMAIL) ?></strong></p>
  <a class="btn" href="?send=1">📧 Enviar email de prueba ahora</a>
</div>
<?php endif; ?>

<div class="box">
  <h2>⚠️ Seguridad</h2>
  <p style="color:#8B9DC3">Elimina este archivo del servidor después de verificar el email: <code>php/test_email.php</code></p>
</div>

<p style="margin-top:2rem"><a href="../index.html" style="color:#00D2FF">← Volver al sitio</a></p>
</body>
</html>
