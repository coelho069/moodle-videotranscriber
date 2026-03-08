<?php
/**
 * force_all.php — Varredura de banco para forçar transcrição de todos os vídeos pendentes.
 *
 * Este script procura por instâncias do módulo Moodle "URL" que possuam um link de vídeo
 * válido (YouTube, mp4, etc.) mas que ainda não tenham registro na tabela do videotranscriber.
 * 
 * Uso via CLI (Recomendado):
 *   php local/videotranscriber/cli/force_all.php
 *
 * Uso via Navegador (Apenas Admin):
 *   SEUDOMINIO/local/videotranscriber/cli/force_all.php
 *
 * @package local_videotranscriber
 */

define('CLI_SCRIPT', PHP_SAPI === 'cli');

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/videotranscriber/classes/observer.php');

global $DB, $CFG, $PAGE;

// Se for acesso por navegador, exige login como admin
if (!CLI_SCRIPT) {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url('/local/videotranscriber/cli/force_all.php');
    echo $OUTPUT->header();
    echo $OUTPUT->heading('Forçando Transcrições Pendentes');
    echo '<pre>';
}

function vt_out($msg) {
    echo $msg . (CLI_SCRIPT ? "\n" : "<br>\n");
    @flush();
    @ob_flush();
}

vt_out("Iniciando varredura por URLs de vídeo sem transcrição...");

// Encontra todas as URLs que NÃO estão na tabela do videotranscriber
// E que pertencem a um course_module válido
$sql = "
    SELECT u.id as urlid, u.course, u.externalurl, cm.id as cmid
    FROM {url} u
    JOIN {modules} m ON m.name = 'url'
    JOIN {course_modules} cm ON cm.instance = u.id AND cm.module = m.id
    LEFT JOIN {local_videotranscriber} vt ON vt.cmid = cm.id
    WHERE vt.id IS NULL
";

$missing_urls = $DB->get_records_sql($sql);

if (!$missing_urls) {
    vt_out("✅ Nenhuma URL de vídeo pendente encontrada. Tudo está atualizado!");
} else {
    $count_total = count($missing_urls);
    $count_queued = 0;
    
    vt_out("Encontradas $count_total URLs sem registro de transcrição. Verificando links...");
    
    foreach ($missing_urls as $record) {
        $exturl = trim($record->externalurl);
        
        // Verifica se é vídeo (mesma heurística da classe observer)
        $is_youtube = preg_match('#(youtube\.com/watch|youtu\.be/|youtube\.com/shorts/)#i', $exturl);
        $path       = parse_url($exturl, PHP_URL_PATH) ?? '';
        $ext        = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $is_video_ext = in_array($ext, ['mp4','mp3','wav','ogg','webm','m4a']);
        
        if ($is_youtube || $is_video_ext) {
            vt_out("  ⏳ Enfileirando URL ID {$record->urlid} (cmid: {$record->cmid}): " . mb_substr($exturl, 0, 50) . "...");
            
            // Força a inserção no banco e enfileiramento do adhoc task
            $triggered = \local_videotranscriber\observer::trigger_transcription(
                $record->cmid, 
                $record->course, 
                $exturl
            );
            
            if ($triggered) {
                vt_out("     ✅ Sucesso.");
                $count_queued++;
            } else {
                vt_out("     ❌ Falha ao enfileirar.");
            }
        }
    }
    
    vt_out("=========================================");
    vt_out("Resumo: $count_queued vídeos enfileirados com sucesso das $count_total URLs analisadas.");
    vt_out("As transcrições ocorrerão no background através do 'cron' (Adhoc Tasks).");
}

if (!CLI_SCRIPT) {
    echo '</pre>';
    echo $OUTPUT->footer();
}
