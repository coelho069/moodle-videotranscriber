<?php
require_once(__DIR__ . '/../../config.php');

// Pega a ultima URL inserida no sistema para testar
global $DB;
$url = $DB->get_record_sql("SELECT * FROM {url} ORDER BY id DESC LIMIT 1");

if ($url) {
    echo "Ultima URL encontrada: " . $url->externalurl . "\n";
    
    $cm = get_coursemodule_from_instance('url', $url->id, $url->course, false, MUST_EXIST);
    
    echo "CMID: " . $cm->id . "\n";
    
    $record = $DB->get_record('local_videotranscriber', ['cmid' => $cm->id]);
    
    if (!$record) {
        echo "Nenhum registro encontrado. Tentando trigger...\n";
        require_local_plugin();
        $triggered = \local_videotranscriber\observer::trigger_transcription($cm->id, $cm->course, $url->externalurl);
        if ($triggered) {
            echo "Trigger executado com SUCESSO!\n";
            $record = $DB->get_record('local_videotranscriber', ['cmid' => $cm->id]);
            print_r($record);
        } else {
            echo "Falha ao executar o trigger.\n";
        }
    } else {
        echo "Registro ja existe:\n";
        print_r($record);
    }
} else {
    echo "Nenhuma URL cadastrada no Moodle para testar.\n";
}

function require_local_plugin() {
    global $CFG;
    require_once($CFG->dirroot . '/local/videotranscriber/classes/observer.php');
}
