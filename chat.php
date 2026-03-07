<?php
// local/videotranscriber/chat.php
defined('MOODLE_INTERNAL') || die();

require_once('../../config.php');
require_login();

global $DB, $CFG;

// resposta JSON
header('Content-Type: application/json; charset=utf-8');

// proteção CSRF
require_sesskey();

// parâmetros
$cmid = required_param('cmid', PARAM_INT);
$question = required_param('question', PARAM_TEXT);

// busca registro da aula
$record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid], '*', MUST_EXIST);

// preferir chunks se tabela existir
$usechunks = $DB->get_manager()->table_exists('local_vt_chunks');

$context = '';

// se houver tabela de chunks, buscar os 5 mais relevantes por busca simples
if ($usechunks) {
    // busca simples: prioriza chunks que contêm palavras da pergunta
    $words = preg_split('/\s+/', mb_strtolower(trim(strip_tags($question))));
    $conds = [];
    $params = [];
    $i = 0;
    foreach ($words as $w) {
        $w = trim($w);
        if ($w === '' || strlen($w) < 3) {
            continue;
        }
        $i++;
        $conds[] = "content LIKE :w$i";
        $params["w$i"] = '%' . $DB->sql_like_escape($w) . '%';
        if ($i >= 6) break;
    }

    if (!empty($conds)) {
        $sql = "SELECT content FROM {local_vt_chunks} WHERE " . implode(' OR ', $conds) . " ORDER BY LENGTH(content) ASC LIMIT 5";
        $rows = $DB->get_records_sql($sql, $params);
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = $r->content;
        }
        $context = implode("\n\n", $parts);
    }
}

// fallback para transcrição inteira truncada
if (trim($context) === '') {
    // limitar para evitar estouro de tokens
    $context = mb_substr((string)$record->transcription, 0, 12000);
}

// montar prompt seguro
$systeminstr = "Você é um tutor educacional. Use exclusivamente o contexto de transcrição fornecido. Se a resposta não estiver na transcrição, responda: \"Não encontrei essa informação na transcrição da aula.\" Explique de forma simples e dê exemplos apenas se estiverem na transcrição.";

// corpo enviado para API
$prompt = "Contexto da transcrição:\n" . $context . "\n\nPergunta do aluno:\n" . $question;

// chave preferencialmente via config do plugin
$apikey = get_config('local_videotranscriber', 'openaiapikey') ?: getenv('OPENAI_API_KEY');

if (empty($apikey)) {
    http_response_code(500);
    echo json_encode(['error' => 'API key não configurada']);
    exit;
}

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systeminstr],
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 500,
    'temperature' => 0.2
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);
$curlerr = curl_error($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro CURL: ' . $curlerr]);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json)) {
    http_response_code(500);
    echo json_encode(['error' => 'Resposta JSON inválida']);
    exit;
}

if ($httpcode < 200 || $httpcode >= 300) {
    $msg = $json['error']['message'] ?? ('Erro HTTP ' . $httpcode);
    http_response_code($httpcode);
    echo json_encode(['error' => $msg, 'raw' => $json]);
    exit;
}

$answer = $json['choices'][0]['message']['content'] ?? '';

if (trim($answer) === '') {
    $answer = 'Resposta vazia';
}

echo json_encode(['answer' => $answer]);
exit;
