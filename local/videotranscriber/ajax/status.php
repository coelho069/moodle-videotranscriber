<?php
/**
 * AJAX endpoint: retorna o status e mensagem da transcrição
 * URL: /local/videotranscriber/ajax/status.php?cmid=<ID>
 */

// Moodle root está 3 níveis acima: local/videotranscriber/ajax -> local/videotranscriber -> local -> moodle_root
require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json; charset=utf-8');

$cmid = required_param('cmid', PARAM_INT);

// Verificação de autenticação mínima
require_login();

$record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);

if (!$record) {
    echo json_encode(['status' => 'none', 'transcription' => '']);
} else {
    echo json_encode([
        'status'       => $record->status,
        'transcription' => $record->transcription ?? '',
    ]);
}
