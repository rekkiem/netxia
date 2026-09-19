<?php
/**
 * NETXIA — mailer.php v2
 * Cascada: Gmail API (HTTPS allowlist 50webs) → SMTP → mail()
 *
 * Hosting: solo hosts Google (gmail.googleapis.com, www.googleapis.com).
 * SMTP y APIs de terceros → errno 13.
 *
 * En php/config.php (NO versionado):
 *   GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET, GMAIL_REFRESH_TOKEN
 *   ADMIN_EMAIL, ADMIN_EMAIL_COPY (opcional, Gmail de respaldo ≠ remitente)
 */
require_once __DIR__ . '/config.php';

defined('GMAIL_CLIENT_ID')     || define('GMAIL_CLIENT_ID', '');
defined('GMAIL_CLIENT_SECRET') || define('GMAIL_CLIENT_SECRET', '');
defined('GMAIL_REFRESH_TOKEN') || define('GMAIL_REFRESH_TOKEN', '');
defined('GMAIL_SEND_URL')      || define('GMAIL_SEND_URL', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
// oauth2.googleapis.com suele bloqueado; www.googleapis.com/oauth2/v4/token pasa en 50webs.
defined('GMAIL_TOKEN_URLS')    || define('GMAIL_TOKEN_URLS', [
    'https://www.googleapis.com/oauth2/v4/token',
    'https://www.googleapis.com/oauth2/v3/token',
    'https://oauth2.googleapis.com/token',
]);
defined('MAIL_FALLBACK_FROM')  || define('MAIL_FALLBACK_FROM', 'no-reply@netxia.cl');
defined('ADMIN_EMAIL_COPY')    || define('ADMIN_EMAIL_COPY', '');

function gmail_api_configured(): bool {
    return GMAIL_CLIENT_ID !== '' && GMAIL_CLIENT_SECRET !== '' && GMAIL_REFRESH_TOKEN !== ''
        && function_exists('curl_init');
}

/** Destinatarios admin: principal + copia de respaldo (F5). */
function netxia_admin_emails(): array {
    $list = [];
    if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '') {
        $list[] = strtolower(trim((string)ADMIN_EMAIL));
    }
    if (defined('ADMIN_EMAIL_COPY') && ADMIN_EMAIL_COPY !== '') {
        $list[] = strtolower(trim((string)ADMIN_EMAIL_COPY));
    }
    return array_values(array_unique(array_filter($list, fn($e) => (bool)filter_var($e, FILTER_VALIDATE_EMAIL))));
}

/** Añade ADMIN_EMAIL (+ COPY) al PHPMailer. */
function netxia_add_admin_recipients(\PHPMailer\PHPMailer\PHPMailer $mail, string $name = 'Netxia'): void {
    $first = true;
    foreach (netxia_admin_emails() as $addr) {
        if ($first) {
            $mail->addAddress($addr, $name);
            $first = false;
        } else {
            $mail->addAddress($addr);
        }
    }
    if ($first) {
        throw new RuntimeException('ADMIN_EMAIL no configurado');
    }
}

/** @return array{0:int,1:?array,2:string} [http_code, json, curl_error] */
function gmail_http(string $url, array $headers, string $body): array {
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $out  = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($c);
    curl_close($c);
    $json = is_string($out) ? json_decode($out, true) : null;
    return [$code, is_array($json) ? $json : null, $err];
}

function gmail_access_token(): string {
    $body = http_build_query([
        'client_id'     => GMAIL_CLIENT_ID,
        'client_secret' => GMAIL_CLIENT_SECRET,
        'refresh_token' => GMAIL_REFRESH_TOKEN,
        'grant_type'    => 'refresh_token',
    ]);
    $last = '';
    foreach (GMAIL_TOKEN_URLS as $url) {
        [$code, $json, $err] = gmail_http($url, ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($code === 200 && !empty($json['access_token'])) {
            return (string)$json['access_token'];
        }
        $last = basename(parse_url($url, PHP_URL_PATH) ?: $url) . ': '
            . ($code ? "HTTP $code " . ($json['error_description'] ?? $json['error'] ?? '') : $err);
        if ($code === 400 || $code === 401) {
            break;
        }
    }
    throw new RuntimeException('token: ' . trim($last));
}

function gmail_probe_token(): array {
    if (!gmail_api_configured()) {
        return ['ok' => false, 'msg' => 'GMAIL_* no configurado en config.php'];
    }
    try {
        $t = gmail_access_token();
        return ['ok' => true, 'msg' => 'Access token OK (' . strlen($t) . ' chars)'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

function gmail_api_send(\PHPMailer\PHPMailer\PHPMailer $mail): void {
    $token = gmail_access_token();
    if (!$mail->preSend()) {
        throw new RuntimeException('MIME: ' . $mail->ErrorInfo);
    }
    $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');
    [$code, $json, $err] = gmail_http(
        GMAIL_SEND_URL,
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['raw' => $raw])
    );
    if ($code !== 200 || empty($json['id'])) {
        throw new RuntimeException('send: ' . ($code ? "HTTP $code " . ($json['error']['message'] ?? '') : $err));
    }
}

/**
 * Envía con cascada. Devuelve true si algún transporte aceptó el mensaje.
 * @param string|null $via transporte exitoso
 * @param string|null $errors detalle si falló todo
 */
function netxia_send(\PHPMailer\PHPMailer\PHPMailer $mail, string $log, ?string &$via = null, ?string &$errors = null): bool {
    $order = gmail_api_configured() ? ['gmail_api', 'smtp', 'mail'] : ['smtp', 'mail'];
    $errs  = [];
    $dest  = implode(', ', netxia_admin_emails()) ?: (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '?');

    foreach ($order as $t) {
        try {
            if ($t === 'gmail_api') {
                gmail_api_send($mail);
            } elseif ($t === 'smtp') {
                if (!defined('SMTP_PASS') || SMTP_PASS === '') {
                    throw new RuntimeException('SMTP_PASS vacío');
                }
                $mail->isSMTP();
                $mail->send();
            } else {
                $mail->isMail();
                $mail->setFrom(MAIL_FALLBACK_FROM, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Netxia');
                $mail->send();
            }
            $via = $t;
            log_event($log, "Email OK vía $t → $dest");
            return true;
        } catch (\Throwable $e) {
            $errs[] = "$t: " . $e->getMessage();
        }
    }
    $errors = implode(' | ', $errs);
    log_event($log, "Email FALLÓ en todos los transportes: $errors", 'ERROR');
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    @file_put_contents(
        LOG_DIR . '/email_errors.log',
        date('c') . ' | ' . strtoupper($log) . " | $errors\n",
        FILE_APPEND | LOCK_EX
    );
    return false;
}

function netxia_mail_health(bool $probeToken = false): array {
    $h = [
        'gmail_api_configured' => gmail_api_configured(),
        'smtp_pass_set'        => defined('SMTP_PASS') && SMTP_PASS !== '',
        'admin_email'          => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '',
        'admin_email_copy'     => defined('ADMIN_EMAIL_COPY') ? ADMIN_EMAIL_COPY : '',
        'recipients'           => netxia_admin_emails(),
        'token_probe'          => null,
        'last_email_errors'    => [],
    ];
    if ($probeToken) {
        $h['token_probe'] = gmail_probe_token();
    }
    $errFile = LOG_DIR . '/email_errors.log';
    if (is_file($errFile)) {
        $lines = @file($errFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $h['last_email_errors'] = array_slice($lines, -8);
    }
    return $h;
}

// ── Leads JSON ───────────────────────────────────────────────────────────────

function leads_dir(string $type): string {
    $map = ['requirements' => 'requirements', 'applications' => 'applications'];
    $sub = $map[$type] ?? 'requirements';
    return DATA_DIR . '/' . $sub;
}

function lead_set_notification(string $type, string $id, bool $notificado, ?string $via = null, ?string $error = null): bool {
    $dir = leads_dir($type);
    if (!is_dir($dir)) {
        return false;
    }
    $files = glob($dir . '/*.json') ?: [];
    rsort($files);
    foreach ($files as $jf) {
        $arr = json_decode((string)@file_get_contents($jf), true);
        if (!is_array($arr)) {
            continue;
        }
        $changed = false;
        foreach ($arr as $i => $row) {
            if (($row['id'] ?? '') !== $id) {
                continue;
            }
            $arr[$i]['notificado']     = $notificado;
            $arr[$i]['notif_via']      = $via;
            $arr[$i]['notif_error']    = $error;
            $arr[$i]['notif_at']       = date('c');
            $arr[$i]['notif_attempts'] = (int)($row['notif_attempts'] ?? 0) + 1;
            $changed = true;
            break;
        }
        if ($changed) {
            return (bool)@file_put_contents(
                $jf,
                json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                LOCK_EX
            );
        }
    }
    return false;
}

function leads_list(string $type, int $maxFiles = 6): array {
    $dir = leads_dir($type);
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach (array_slice($files, 0, $maxFiles) as $jf) {
        $arr = json_decode((string)@file_get_contents($jf), true);
        if (!is_array($arr)) {
            continue;
        }
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['_file'] = basename($jf);
            $row['_type'] = $type;
            if (!array_key_exists('notificado', $row)) {
                $row['notificado'] = null;
            }
            $out[] = $row;
        }
    }
    usort($out, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));
    return $out;
}

function lead_find(string $type, string $id): ?array {
    foreach (leads_list($type, 24) as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/** Reconstruye PHPMailer desde un lead guardado (reintento admin). */
function lead_build_mail(string $type, array $row): \PHPMailer\PHPMailer\PHPMailer {
    $mail = create_mailer();
    if ($type === 'applications') {
        netxia_add_admin_recipients($mail, 'Netxia RRHH');
        $nombre = (string)($row['nombre'] ?? '');
        $email  = (string)($row['email'] ?? '');
        $cargo  = (string)($row['cargo'] ?? '');
        if ($email !== '') {
            $mail->addReplyTo($email, $nombre);
        }
        $cv = (string)($row['cv_archivo'] ?? '');
        if ($cv !== '' && is_file(UPLOAD_DIR . '/' . $cv)) {
            $ext = pathinfo($cv, PATHINFO_EXTENSION) ?: 'pdf';
            $mail->addAttachment(UPLOAD_DIR . '/' . $cv, 'CV_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombre) . '.' . $ext);
        }
        $mail->Subject = '👤 Postulación: ' . $nombre . ' — ' . $cargo;
        $mail->isHTML(true);
        $linkedin = (string)($row['linkedin'] ?? '');
        $mail->Body = "
<div style='font-family:Arial,sans-serif;max-width:600px;background:#06091A;color:#EEF2FF;padding:24px;border-radius:12px'>
  <h2 style='color:#00D2FF'>Postulación (reintento)</h2>
  <p><strong>Nombre:</strong> " . htmlspecialchars($nombre) . "<br>
  <strong>Email:</strong> " . htmlspecialchars($email) . "<br>
  <strong>Cargo:</strong> " . htmlspecialchars($cargo) . "<br>
  <strong>Experiencia:</strong> " . htmlspecialchars((string)($row['experiencia'] ?? '')) . "<br>
  <strong>LinkedIn:</strong> " . htmlspecialchars($linkedin) . "</p>
  <p>" . nl2br(htmlspecialchars((string)($row['carta'] ?? ''))) . "</p>
  <p style='color:#8B9DC3;font-size:12px'>ID: " . htmlspecialchars((string)($row['id'] ?? '')) . "</p>
</div>";
        $mail->AltBody = "Postulación $nombre $cargo\n$email\n" . ($row['carta'] ?? '');
    } else {
        netxia_add_admin_recipients($mail, 'Netxia');
        $nombre  = (string)($row['nombre'] ?? '');
        $empresa = (string)($row['empresa'] ?? '');
        $email   = (string)($row['email'] ?? '');
        $servicio = (string)($row['servicio'] ?? '');
        if ($email !== '') {
            $mail->addReplyTo($email, $nombre);
        }
        $mail->Subject = '🔔 Requerimiento: ' . $empresa . ' (' . $servicio . ')';
        $mail->isHTML(true);
        $mail->Body = "
<div style='font-family:Arial,sans-serif;max-width:600px;background:#06091A;color:#EEF2FF;padding:24px;border-radius:12px'>
  <h2 style='color:#00D2FF'>Requerimiento (reintento)</h2>
  <p><strong>Nombre:</strong> " . htmlspecialchars($nombre) . "<br>
  <strong>Empresa:</strong> " . htmlspecialchars($empresa) . "<br>
  <strong>Email:</strong> " . htmlspecialchars($email) . "<br>
  <strong>Tel:</strong> " . htmlspecialchars((string)($row['telefono'] ?? '')) . "<br>
  <strong>Servicio:</strong> " . htmlspecialchars($servicio) . "</p>
  <p>" . nl2br(htmlspecialchars((string)($row['detalle'] ?? ''))) . "</p>
  <p style='color:#8B9DC3;font-size:12px'>ID: " . htmlspecialchars((string)($row['id'] ?? '')) . "</p>
</div>";
        $mail->AltBody = "Requerimiento $nombre ($empresa)\n$email\n" . ($row['detalle'] ?? '');
    }
    return $mail;
}

function lead_resend(string $type, string $id): array {
    $row = lead_find($type, $id);
    if (!$row) {
        return ['ok' => false, 'msg' => 'Lead no encontrado'];
    }
    $via = null;
    $errors = null;
    try {
        $mail = lead_build_mail($type, $row);
        $ok = netxia_send($mail, $type === 'applications' ? 'jobs' : 'requirements', $via, $errors);
        lead_set_notification($type, $id, $ok, $via, $ok ? null : $errors);
        return $ok
            ? ['ok' => true, 'msg' => "Notificado vía $via", 'via' => $via]
            : ['ok' => false, 'msg' => $errors ?: 'Fallo envío', 'via' => null];
    } catch (\Throwable $e) {
        lead_set_notification($type, $id, false, null, $e->getMessage());
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}
