<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for answering questions using OpenAI and transcription chunks.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();
require_sesskey();

global $DB;

header('Content-Type: application/json; charset=utf-8');

$cmid = required_param('cmid', PARAM_INT);
$question = required_param('question', PARAM_TEXT);

// Validate context and capabilities.
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
$context = context_module::instance($cmid);
require_capability('mod/url:view', $context);

// Fetch transcription record.
$record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid], '*', MUST_EXIST);

// Prefer chunks if the table exists.
$usechunks = $DB->get_manager()->table_exists('local_vt_chunks');

$contexttext = '';

// Search for relevant chunks by matching question words.
if ($usechunks) {
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
        $paramname = 'w' . $i;
        $conds[] = $DB->sql_like('content', ':' . $paramname, false);
        $params[$paramname] = '%' . $DB->sql_like_escape($w) . '%';
        if ($i >= 6) {
            break;
        }
    }

    if (!empty($conds)) {
        $sql = "SELECT content FROM {local_vt_chunks}
                 WHERE " . implode(' OR ', $conds) . "
              ORDER BY LENGTH(content) ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, 5);
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = $r->content;
        }
        $contexttext = implode("\n\n", $parts);
    }
}

// Fallback to truncated full transcription.
if (trim($contexttext) === '') {
    $contexttext = mb_substr((string) $record->transcription, 0, 12000);
}

$systeminstr = "You are an educational tutor. Use exclusively the transcription context provided. "
    . "If the answer is not in the transcription, respond: \"I could not find this information in the lesson transcription.\" "
    . "Explain simply and give examples only if they are in the transcription.";

$prompt = "Transcription context:\n" . $contexttext . "\n\nStudent question:\n" . $question;

$apikey = get_config('local_videotranscriber', 'openai_api_key');
if (empty($apikey)) {
    http_response_code(500);
    echo json_encode(['error' => get_string('error_apikey', 'local_videotranscriber')]);
    exit;
}

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systeminstr],
        ['role' => 'user', 'content' => $prompt],
    ],
    'max_tokens' => 500,
    'temperature' => 0.2,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey,
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$curlerr = curl_error($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => get_string('error_connection', 'local_videotranscriber')]);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json)) {
    http_response_code(500);
    echo json_encode(['error' => get_string('error_invalidresponse', 'local_videotranscriber')]);
    exit;
}

if ($httpcode < 200 || $httpcode >= 300) {
    $msg = $json['error']['message'] ?? ('HTTP ' . $httpcode);
    http_response_code($httpcode);
    echo json_encode(['error' => $msg]);
    exit;
}

$answer = $json['choices'][0]['message']['content'] ?? '';

if (trim($answer) === '') {
    $answer = get_string('error_emptyresponse', 'local_videotranscriber');
}

echo json_encode(['answer' => $answer]);
