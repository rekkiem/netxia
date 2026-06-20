<!DOCTYPE html>
<?php
/**
 * NETXIA — Diagnóstico Gmail SMTP
 * URL: http://localhost/netxia/php/test_email.php
 * ⚠️  ELIMINAR del servidor de producción tras verificar
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/config.php';

if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', '');
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash-lite');
if (!defined('CHATBOT_LOCAL_FALLBACK')) define('CHATBOT_LOCAL_FALLBACK', true);

$result   = null;
$debugLog = [];
$sending  = isset($_GET['send']);

if ($sending) {
    try {
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        require_once __DIR__ . '/phpmailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug  = 3;
        $mail->Debugoutput = function($str) use (&$debugLog) { $debugLog[] = trim($str); };

        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL);
        $mail->isHTML(true);
        $mail->Subject = '✅ Test Gmail SMTP — Netxia ' . date('H:i:s');
        $mail->Body    = '<h2 style="color:#00D2FF">¡Gmail SMTP funcionando!</h2><p>Enviado: ' . date('Y-m-d H:i:s') . '</p><p>De: ' . SMTP_USER . ' → Para: ' . ADMIN_EMAIL . '</p>';
        $mail->send();
        $result = ['ok' => true, 'msg' => '✅ Email enviado a ' . ADMIN_EMAIL . '. Revisa tu bandeja.'];
    } catch (\Exception $e) {
        $result = ['ok' => false, 'msg' => '❌ ' . $e->getMessage()];
    }
}

$checks = [
    'SMTP Host = smtp.gmail.com'     => SMTP_HOST === 'smtp.gmail.com',
    'SMTP_USER configurado'          => !empty(SMTP_USER),
    'SMTP_PASS (App Password) set'   => !empty(SMTP_PASS),
    'ADMIN_EMAIL configurado'        => !empty(ADMIN_EMAIL),
    'Chatbot utilizable'             => !empty(GEMINI_API_KEY) || CHATBOT_LOCAL_FALLBACK,
    'Fallback local habilitado'      => CHATBOT_LOCAL_FALLBACK,
    'curl habilitado'                => function_exists('curl_init'),
    'finfo disponible'               => class_exists('finfo'),
    'data/ escribible'               => is_writable(DATA_DIR) || is_dir(DATA_DIR),
    'logs/ disponible'               => is_dir(LOG_DIR) || @mkdir(LOG_DIR, 0755, true),
    'uploads/cv/ disponible'         => is_dir(UPLOAD_DIR) || @mkdir(UPLOAD_DIR, 0755, true),
];
?>
<html lang="es"><head><meta charset="UTF-8"><title>Diagnóstico Gmail SMTP — Netxia</title>
<style>
  *{box-sizing:border-box}body{font-family:'Segoe UI',monospace;background:#06091A;color:#EEF2FF;padding:2rem;margin:0}
  h1{color:#00D2FF;font-size:1.5rem;margin-bottom:1.5rem}
  h2{color:#00E887;font-size:.8rem;text-transform:uppercase;letter-spacing:.1em;margin:1.5rem 0 .75rem}
  .box{background:#0D1230;border:1px solid rgba(0,210,255,.2);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.25rem}
  .row{display:flex;gap:1rem;padding:.45rem 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.9rem}
  .row:last-child{border:0}.ok{color:#00E887}.fail{color:#FF3864}.warn{color:#FFB800}
  pre{background:#080C20;border-radius:8px;padding:1rem;font-size:.78rem;color:#8B9DC3;overflow-x:auto;max-height:260px;overflow-y:auto;white-space:pre-wrap}
  .r-ok{background:rgba(0,232,135,.08);border:1px solid rgba(0,232,135,.35);border-radius:10px;padding:1rem;color:#00E887;margin-bottom:1rem}
  .r-fail{background:rgba(255,56,100,.08);border:1px solid rgba(255,56,100,.35);border-radius:10px;padding:1rem;color:#FF8AA7;margin-bottom:1rem}
  .warn-box{background:rgba(255,184,0,.07);border:1px solid rgba(255,184,0,.3);border-radius:10px;padding:1rem;color:#FFB800;margin-bottom:1rem;line-height:1.6}
  a.btn{display:inline-block;padding:.7rem 1.5rem;background:linear-gradient(135deg,#00D2FF,#00E887);color:#06091A;font-weight:700;border-radius:10px;text-decoration:none;margin-top:.75rem}
  code{background:rgba(0,210,255,.1);padding:.1rem .4rem;border-radius:4px;font-size:.85em}
</style></head><body>
<h1>🔧 Diagnóstico Gmail SMTP — Netxia</h1>

<?php if ($result): ?>
  <div class="<?= $result['ok'] ? 'r-ok' : 'r-fail' ?>"><?= $result['msg'] ?></div>
  <?php if ($debugLog): ?>
    <h2>Log SMTP detallado</h2><pre><?= htmlspecialchars(implode("\n", $debugLog)) ?></pre>
  <?php endif; ?>
<?php endif; ?>

<div class="box">
  <h2>Estado de configuración</h2>
  <?php foreach ($checks as $label => $ok): ?>
    <div class="row"><span class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✅' : '❌' ?></span><span><?= $label ?></span></div>
  <?php endforeach; ?>
</div>

<div class="box">
  <h2>Valores actuales</h2>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">SMTP_HOST</span><span><?= htmlspecialchars(SMTP_HOST) ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">SMTP_PORT</span><span><?= SMTP_PORT ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">SMTP_USER (From)</span><span><?= htmlspecialchars(SMTP_USER) ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">SMTP_PASS</span>
    <span><?= empty(SMTP_PASS) ? '<span class="fail">⚠️ VACÍO — email no se enviará</span>' : '<span class="ok">✅ ' . strlen(SMTP_PASS) . ' caracteres</span>' ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">ADMIN_EMAIL (To)</span><span><?= htmlspecialchars(ADMIN_EMAIL) ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">GEMINI_MODEL</span><span><?= htmlspecialchars(GEMINI_MODEL) ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">GEMINI_API_KEY</span>
    <span><?= empty(GEMINI_API_KEY) ? '<span class="warn">⚠️ Vacía — se usará FAQ local sin costo</span>' : '<span class="ok">✅ configurada</span>' ?></span></div>
  <div class="row"><span style="color:#8B9DC3;width:160px;flex-shrink:0">Fallback local</span>
    <span><?= CHATBOT_LOCAL_FALLBACK ? '<span class="ok">✅ habilitado</span>' : '<span class="fail">❌ deshabilitado</span>' ?></span></div>
</div>

<?php if (empty(SMTP_PASS)): ?>
<div class="warn-box">
  <strong>⚠️ SMTP_PASS vacío.</strong> Pasos para obtener tu App Password de Gmail:<br><br>
  1. Ve a <a href="https://myaccount.google.com/security" target="_blank" style="color:#00D2FF">myaccount.google.com/security</a><br>
  2. Activa <strong>Verificación en 2 pasos</strong> (si no está activa)<br>
  3. Busca <strong>"Contraseñas de aplicaciones"</strong><br>
  4. Crea una nueva: Aplicación = "Correo", Dispositivo = "Otro" → escribe "Netxia"<br>
  5. Copia los <strong>16 caracteres</strong> generados (sin espacios)<br>
  6. Pégalos en <code>php/config.php</code> → <code>define('SMTP_PASS', 'abcdabcdabcdabcd');</code><br>
  7. Sube el config.php y recarga esta página
</div>
<?php else: ?>
<div class="box">
  <h2>Probar envío ahora</h2>
  <p style="color:#8B9DC3;margin-bottom:.75rem">Enviará un email de prueba de <strong><?= htmlspecialchars(SMTP_USER) ?></strong> a <strong><?= htmlspecialchars(ADMIN_EMAIL) ?></strong></p>
  <a class="btn" href="?send=1">📧 Enviar email de prueba</a>
</div>
<?php endif; ?>

<div class="box" style="border-color:rgba(255,56,100,.3)">
  <h2 style="color:#FF8AA7">⚠️ Seguridad</h2>
  <p style="color:#8B9DC3">Elimina este archivo del servidor de producción: <code>php/test_email.php</code></p>
</div>
<p style="margin-top:1.5rem"><a href="../index.html" style="color:#00D2FF">← Volver al sitio</a></p>
</body></html>
