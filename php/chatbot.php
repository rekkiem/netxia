<?php
/**
 * NETXIA — Chatbot proxy v1.2
 * Fix: display_errors suprimido, graceful curl check, JSON siempre válido
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR);

require_once __DIR__ . '/config.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido.');
}

// ── Verificar que curl está disponible ────────────────────────────────
if (!function_exists('curl_init')) {
    log_event('chatbot', 'curl no disponible en este servidor', 'ERROR');
    json_response(false, 'El asistente no está disponible en el servidor local. Contáctanos a contacto@netxia.cl');
}

// ── Rate limiting (desactivado en local) ──────────────────────────────
$rl_key = 'chat_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_CHAT)) {
    json_response(false, 'Demasiados mensajes. Intenta en unos minutos.');
}

// ── Validar API key ───────────────────────────────────────────────────
if (empty(ANTHROPIC_API_KEY) || ANTHROPIC_API_KEY === 'sk-ant-XXXXXXXXX') {
    json_response(false, 'Chatbot no configurado. Agrega tu API key en php/config.php y reinicia Apache.');
}

// ── Parse input ───────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(false, 'JSON de entrada inválido.');
}

$message = sanitize($body['message'] ?? '', 1000);
$history = $body['history'] ?? [];

if (empty($message)) {
    json_response(false, 'Mensaje vacío.');
}

// ── Construir historial ───────────────────────────────────────────────
$messages = [];
foreach (array_slice($history, -10) as $turn) {
    if (!in_array($turn['role'] ?? '', ['user', 'assistant'])) continue;
    $messages[] = [
        'role'    => $turn['role'],
        'content' => mb_substr((string)($turn['content'] ?? ''), 0, 1000),
    ];
}
$messages[] = ['role' => 'user', 'content' => $message];

// ── System prompt ─────────────────────────────────────────────────────
$system = 'Eres el asistente virtual de Netxia, consultora TI chilena especializada en Inteligencia Artificial, Ciberseguridad y Cloud/DevOps. Atiendes a empresas chilenas.

PERSONALIDAD: Profesional pero cercano. Español chileno formal. Conciso y útil. Máximo 3 párrafos.

SERVICIOS:
- IA & ML: agentes autónomos, automatización, modelos custom
- Ciberseguridad: Zero Trust, SOC 24/7, pentesting, ISO 27001, CMF
- Cloud & DevOps: migración AWS/Azure/GCP, CI/CD, Terraform, Kubernetes

MÉTRICAS: +200 proyectos, 95% satisfacción, 10+ años, clientes: Falabella, Walmart Chile, Mall Plaza.
CONTACTO: contacto@netxia.cl | +56 9 8902 4643 | Santiago, Chile

Si piden cotización, solicita nombre, empresa y email. Nunca inventes precios exactos.';

// ── Llamar Claude API ─────────────────────────────────────────────────
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
    CURLOPT_SSL_VERIFYPEER => !IS_LOCAL, // En local no verificar SSL para evitar problemas de cert
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

if ($curlErr) {
    log_event('chatbot', "curl error: $curlErr", 'ERROR');
    json_response(false, 'No se pudo conectar con el asistente. Verifica tu conexión a internet.');
}

if ($httpCode !== 200) {
    $errBody = json_decode($response, true);
    $errMsg  = $errBody['error']['message'] ?? "HTTP $httpCode";
    log_event('chatbot', "API error $httpCode: $errMsg", 'ERROR');

    // Mensajes de error amigables según código
    $friendly = match(true) {
        $httpCode === 401 => 'API key inválida. Verifica ANTHROPIC_API_KEY en config.php.',
        $httpCode === 429 => 'Límite de uso de API alcanzado. Intenta en unos minutos.',
        $httpCode >= 500  => 'El servicio de IA está temporalmente no disponible.',
        default           => "Error al procesar la respuesta (HTTP $httpCode).",
    };
    json_response(false, $friendly);
}

$data  = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? '';

if (empty($reply)) {
    json_response(false, 'Respuesta vacía del asistente. Intenta de nuevo.');
}

log_event('chatbot', 'msg: ' . mb_substr($message, 0, 80));
json_response(true, 'ok', ['reply' => $reply]);
