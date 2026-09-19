<?php
/**
 * NETXIA — mail_test.php (TEMPORAL — bórralo tras verificar)
 * https://netxia.cl/php/mail_test.php?k=TU_TOKEN            → prueba token OAuth (no envía nada)
 * https://netxia.cl/php/mail_test.php?k=TU_TOKEN&send=1     → además envía 1 correo a ADMIN_EMAIL
 */
$TOKEN = 'CAMBIA_ESTO';
if (!hash_equals($TOKEN, (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/mailer.php';

echo "Gmail API configurada: " . (gmail_api_configured() ? 'sí' : 'NO (faltan GMAIL_CLIENT_ID / SECRET / REFRESH_TOKEN o curl)') . "\n";
if (!gmail_api_configured()) exit;

echo "1) Access token … ";
try { $t = gmail_access_token(); echo "OK (" . strlen($t) . " chars)\n"; }
catch (Throwable $e) { echo "FALLÓ → " . $e->getMessage() . "\n"; exit; }

if (($_GET['send'] ?? '') !== '1') { echo "2) Envío omitido (agrega &send=1)\n"; exit; }

echo "2) Enviando a " . ADMIN_EMAIL . " … ";
$mail = create_mailer();
$mail->addAddress(ADMIN_EMAIL, 'Netxia');
$mail->Subject = '✅ Test Gmail API — Netxia ' . date('H:i:s');
$mail->isHTML(true);
$mail->Body    = '<h2>Gmail API funcionando</h2><p>' . date('c') . '</p>';
$mail->AltBody = 'Gmail API funcionando ' . date('c');
$ok = netxia_send($mail, 'test', $via, $errs);
echo $ok ? "OK vía $via\n" : "FALLÓ → $errs\n";
