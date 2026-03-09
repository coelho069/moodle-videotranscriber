<?php
/**
 * AJAX endpoint for the AI Tutor
 * Receives a question string and cmid, and returns the AI's response in JSON format.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$question = required_param('question', PARAM_TEXT);

// Require login and capabilities
$cm = get_coursemodule_from_id('url', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_login($course, false, $cm);
/** @var \context $context */
$context = context_module::instance($cmid);
if (!$context) {
    echo json_encode(['error' => 'Invalid context.']);
    exit;
}
require_capability('mod/url:view', $context);

$url_mod = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

// Get the transcription
$vt_record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);
if (!$vt_record || $vt_record->status !== 'completed' || empty($vt_record->transcription)) {
    echo json_encode(['error' => 'A transcrição não está disponível.']);
    exit;
}

$transcription = $vt_record->transcription;
$video_name = format_string($url_mod->name);

// Check if we have the class tutor_service available, if so use it, otherwise fallback
$apikey = get_config('local_videotranscriber', 'openaiapikey');
if (empty($apikey)) {
    // try getenv just in case
    $apikey = getenv('OPENAI_API_KEY');
}

if (empty($apikey)) {
    echo json_encode(['error' => 'A API Key da OpenAI não está configurada no sistema.']);
    exit;
}

$system_prompt = "Você é um tutor de IA especializado no conteúdo do seguinte vídeo: \"$video_name\".\n" .
    "Responda APENAS com base na transcrição abaixo. " .
    "Não invente informações. Se a pergunta não puder ser respondida com o conteúdo do vídeo, diga isso claramente.\n\n" .
    "=== TRANSCRIÇÃO DO VÍDEO ===\n" . mb_substr($transcription, 0, 12000) . "\n=== FIM DA TRANSCRIÇÃO ===";

$payload = json_encode([
    'model'    => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system',  'content' => $system_prompt],
        ['role' => 'user',    'content' => $question],
    ],
    'max_tokens'  => 1024,
    'temperature' => 0.3,
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apikey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$raw = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    echo json_encode(['error' => 'Erro de conexão com OpenAI: ' . $curl_error]);
    exit;
}

$data = json_decode($raw, true);

if ($httpcode >= 400 || !empty($data['error'])) {
    $errormsg = $data['error']['message'] ?? 'Erro desconhecido da API.';
    echo json_encode(['error' => 'Erro da API (HTTP ' . $httpcode . '): ' . $errormsg]);
    exit;
}

$ai_response = $data['choices'][0]['message']['content'] ?? '❌ Erro ao obter resposta da IA. Tente novamente.';

echo json_encode(['answer' => $ai_response]);
exit;
