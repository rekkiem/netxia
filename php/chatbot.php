<?php
/**
 * NETXIA Chatbot — Proxy a Claude API
 * Mantiene la API key en el servidor (nunca expuesta al cliente)
 */
require_once __DIR__ . '/config.php';
netxia_session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido.');
}

// Rate limiting
$rl_key = 'chat_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
if (!rate_limit($rl_key, RATE_LIMIT_CHAT)) {
    json_response(false, 'Demasiados mensajes. Intenta en unos minutos.');
}

// Validar API key configurada
if (empty(ANTHROPIC_API_KEY)) {
    json_response(false, 'Chatbot no configurado. Agrega tu API key en php/config.php.');
}

// Parse input
$body    = json_decode(file_get_contents('php://input'), true);
$message = sanitize($body['message'] ?? '', 1000);
$history = $body['history'] ?? [];

if (empty($message)) {
    json_response(false, 'Mensaje vacío.');
}

// Construir mensajes (máx 10 turnos de historial)
$messages = [];
foreach (array_slice($history, -10) as $turn) {
    if (!in_array($turn['role'] ?? '', ['user', 'assistant'])) continue;
    $messages[] = [
        'role'    => $turn['role'],
        'content' => mb_substr($turn['content'], 0, 1000),
    ];
}
$messages[] = ['role' => 'user', 'content' => $message];

// System prompt de Netxia
$system = <<<PROMPT
Eres el asistente virtual de Netxia, consultora TI chilena especializada en Inteligencia Artificial, Ciberseguridad y Cloud/DevOps. Atiendes a empresas chilenas.

PERSONALIDAD: Profesional pero cercano. Hablas en español chileno formal (no jerga). Eres conciso y útil.

SERVICIOS NETXIA:
- Inteligencia Artificial & Machine Learning: agentes autónomos, automatización, modelos custom
- Ciberseguridad: Zero Trust, SOC 24/7, pentesting, cumplimiento (ISO 27001, GDPR)
- Cloud & DevOps: migración AWS/Azure/GCP, CI/CD, arquitecturas escalables

MÉTRICAS: +200 proyectos exitosos, 95% satisfacción, 10+ años experiencia, clientes: Falabella, Walmart Chile, Mall Plaza.

CONTACTO: contacto@netxia.cl | +56 9 8902 4643 | Santiago, Chile

INSTRUCCIONES:
- Responde preguntas sobre servicios, precios referenciales, casos de uso.
- Si piden cotización o reunión, pídeles nombre, empresa y email para conectarlos con un ejecutivo.
- Si la pregunta no es de TI, redirige amablemente.
- Máximo 3 párrafos por respuesta. Usa bullet points cuando listes servicios.
- NUNCA inventes precios específicos. Di "desde UF X" o "según alcance del proyecto".
PROMPT;

// Llamar a Claude API
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

if ($curlErr || $httpCode !== 200) {
    log_event('chatbot', "Error API: HTTP $httpCode | $curlErr", 'ERROR');
    json_response(false, 'Error al conectar con el asistente. Intenta nuevamente.');
}

$data  = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? '';

if (empty($reply)) {
    json_response(false, 'Respuesta vacía del asistente.');
}

log_event('chatbot', "msg: " . mb_substr($message, 0, 80));
json_response(true, 'ok', ['reply' => $reply]);
