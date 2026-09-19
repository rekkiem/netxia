<?php
/**
 * NETXIA — mailer.php (hotfix v1)
 * Envío de correo con cascada:  Gmail API (HTTPS) → SMTP (PHPMailer) → mail()
 *
 * Por qué existe: el hosting solo permite conexiones de salida a una lista blanca
 * (gmail.googleapis.com y www.googleapis.com pasan; smtp.gmail.com, oauth2.googleapis.com,
 * Brevo, Resend, etc. dan errno 13). Gmail API por HTTPS es el único camino confirmado.
 *
 * Requiere en php/config.php (NO versionado):
 *   define('GMAIL_CLIENT_ID',     '....apps.googleusercontent.com');
 *   define('GMAIL_CLIENT_SECRET', '....');
 *   define('GMAIL_REFRESH_TOKEN', '1//....');
 */
require_once __DIR__ . '/config.php';

defined('GMAIL_CLIENT_ID')     || define('GMAIL_CLIENT_ID', '');
defined('GMAIL_CLIENT_SECRET') || define('GMAIL_CLIENT_SECRET', '');
defined('GMAIL_REFRESH_TOKEN') || define('GMAIL_REFRESH_TOKEN', '');
defined('GMAIL_SEND_URL')      || define('GMAIL_SEND_URL', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
// oauth2.googleapis.com está bloqueado en 50webs; www.googleapis.com/oauth2/v4/token es el mismo servicio (endpoint legacy).
defined('GMAIL_TOKEN_URLS')    || define('GMAIL_TOKEN_URLS', [
    'https://www.googleapis.com/oauth2/v4/token',
    'https://www.googleapis.com/oauth2/v3/token',
    'https://oauth2.googleapis.com/token',
]);
// Remitente del último recurso mail(): debe ser del dominio propio (un From @gmail.com desde otro servidor cae en spam/rechazo)
defined('MAIL_FALLBACK_FROM')  || define('MAIL_FALLBACK_FROM', 'no-reply@netxia.cl');

function gmail_api_configured(): bool {
    return GMAIL_CLIENT_ID !== '' && GMAIL_CLIENT_SECRET !== '' && GMAIL_REFRESH_TOKEN !== ''
        && function_exists('curl_init');
}

/** @return array{0:int,1:?array,2:string} [http_code, json, curl_error] */
function gmail_http(string $url, array $headers, string $body): array {
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
    ]);
    $out  = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err  = curl_error($c);
    curl_close($c);
    $json = is_string($out) ? json_decode($out, true) : null;
    return [$code, is_array($json) ? $json : null, $err];
}

/** Access token vía refresh token. Prueba cada endpoint hasta que uno responda. */
function gmail_access_token(): string {
    $body = http_build_query([
        'client_id' => GMAIL_CLIENT_ID, 'client_secret' => GMAIL_CLIENT_SECRET,
        'refresh_token' => GMAIL_REFRESH_TOKEN, 'grant_type' => 'refresh_token',
    ]);
    $last = '';
    foreach (GMAIL_TOKEN_URLS as $url) {
        [$code, $json, $err] = gmail_http($url, ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($code === 200 && !empty($json['access_token'])) return (string)$json['access_token'];
        $last = basename(dirname($url)) . "/" . basename($url) . ': ' . ($code ? "HTTP $code " . ($json['error_description'] ?? $json['error'] ?? '') : $err);
        // Si Google respondió con un error de credenciales (400/401), otro endpoint dará lo mismo: cortar.
        if ($code === 400 || $code === 401) break;
    }
    throw new RuntimeException('token: ' . trim($last));
}

/** Envía el mensaje ya armado en PHPMailer usando Gmail API. Lanza excepción si falla. */
function gmail_api_send(\PHPMailer\PHPMailer\PHPMailer $mail): void {
    $token = gmail_access_token();
    if (!$mail->preSend()) throw new RuntimeException('MIME: ' . $mail->ErrorInfo);
    $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');
    [$code, $json, $err] = gmail_http(GMAIL_SEND_URL,
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['raw' => $raw]));
    if ($code !== 200 || empty($json['id'])) {
        throw new RuntimeException('send: ' . ($code ? "HTTP $code " . ($json['error']['message'] ?? '') : $err));
    }
}

/**
 * Envía con cascada y registra el resultado. Devuelve true si algún transporte lo aceptó.
 * $log = nombre del log de evento ('requirements' | 'jobs' | 'test').
 * @param string|null $via  (salida) transporte que funcionó
 */
function netxia_send(\PHPMailer\PHPMailer\PHPMailer $mail, string $log, ?string &$via = null, ?string &$errors = null): bool {
    $order = gmail_api_configured() ? ['gmail_api', 'smtp', 'mail'] : ['smtp', 'mail'];
    $errs  = [];
    foreach ($order as $t) {
        try {
            if ($t === 'gmail_api') {
                gmail_api_send($mail);
            } elseif ($t === 'smtp') {
                if (SMTP_PASS === '') throw new RuntimeException('SMTP_PASS vacío');
                $mail->send();
            } else {
                $mail->isMail();
                $mail->setFrom(MAIL_FALLBACK_FROM, SMTP_FROM_NAME);
                $mail->send();
            }
            $via = $t;
            log_event($log, "Email OK vía $t → " . ADMIN_EMAIL);
            return true;
        } catch (\Throwable $e) {
            $errs[] = "$t: " . $e->getMessage();
        }
    }
    $errors = implode(' | ', $errs);
    log_event($log, "Email FALLÓ en todos los transportes: $errors", 'ERROR');
    @file_put_contents(LOG_DIR . '/email_errors.log', date('c') . " | " . strtoupper($log) . " | $errors\n", FILE_APPEND | LOCK_EX);
    return false;
}
