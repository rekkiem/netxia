<?php
/**
 * NETXIA — Chatbot proxy v2.0
 * Claude API · curl check · SSL local fix · JSON siempre válido
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);
require_once __DIR__ . '/config.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false, 'Método no permitido.');
if (!function_exists('curl_init'))         json_response(false, 'El asistente no está disponible (curl deshabilitado). Habilita la extensión curl en php.ini.');
if (!rate_limit('chat_' . ($_SERVER['REMOTE_ADDR'] ?? ''), RATE_LIMIT_CHAT)) json_response(false, 'Demasiados mensajes. Intenta en unos minutos.');
if (empty(ANTHROPIC_API_KEY))              json_response(false, 'Chatbot sin configurar. Agrega ANTHROPIC_API_KEY en php/config.php.');

$body    = json_decode(file_get_contents('php://input'), true);
$message = sanitize($body['message'] ?? '', 1000);
$history = $body['history'] ?? [];
if (empty($message)) json_response(false, 'Mensaje vacío.');

// Historial (últimas 10 interacciones)
$messages = [];
foreach (array_slice($history, -10) as $t) {
    if (!in_array($t['role'] ?? '', ['user','assistant'])) continue;
    $messages[] = ['role' => $t['role'], 'content' => mb_substr((string)($t['content'] ?? ''), 0, 1000)];
}
$messages[] = ['role' => 'user', 'content' => $message];

$system = 'Eres el asistente virtual de Netxia, consultora TI chilena especializada en IA, Ciberseguridad y Cloud/DevOps.

PERSONALIDAD: Profesional pero cercano. Español chileno formal. Respuestas cortas (máx 3 párrafos).

SERVICIOS:
- IA & ML: agentes autónomos, automatización, modelos custom, RAG enterprise
- Ciberseguridad: Zero Trust, SOC 24/7, pentesting, ISO 27001, cumplimiento CMF
- Cloud & DevOps: AWS/Azure/GCP, Terraform, Kubernetes, CI/CD

DATOS: +200 proyectos, 95% satisfacción, 10+ años. Clientes: Falabella, Walmart Chile, Mall Plaza.
CONTACTO: contacto@netxia.cl | +56 9 8902 4643 | Santiago, Chile

REGLAS: Si piden cotización solicita nombre, empresa y email. No inventes precios exactos. Si no es TI, redirige amablemente.';

$payload = json_encode([
    'model'      => CHATBOT_MODEL,
    'max_tokens' => 600,
    'system'     => $system,
    'messages'   => $messages,
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => !IS_LOCAL,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { log_event('chatbot', "curl: $curlErr", 'ERROR'); json_response(false, 'Sin conexión con el asistente. Revisa tu internet.'); }

if ($httpCode !== 200) {
    $apiErr = json_decode($response, true)['error']['message'] ?? "HTTP $httpCode";
    log_event('chatbot', "API $httpCode: $apiErr", 'ERROR');
    $msg = match(true) {
        $httpCode === 401 => 'API key inválida. Verifica ANTHROPIC_API_KEY en config.php.',
        $httpCode === 400 => 'Modelo inválido o solicitud malformada. Verifica CHATBOT_MODEL en config.php.',
        $httpCode === 429 => 'Límite de API alcanzado. Intenta en unos minutos.',
        $httpCode >= 500  => 'Servicio de IA temporalmente no disponible.',
        default           => "Error de API (HTTP $httpCode).",
    };
    json_response(false, $msg);
}

$data  = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? '';
if (empty($reply)) json_response(false, 'Respuesta vacía. Intenta de nuevo.');

log_event('chatbot', 'msg: ' . mb_substr($message, 0, 80));
json_response(true, 'ok', ['reply' => $reply]);
