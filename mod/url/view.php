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
 * URL module main user interface - modified for VideoTranscriber
 *
 * @package    mod_url
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once("$CFG->dirroot/mod/url/lib.php");
require_once("$CFG->dirroot/mod/url/locallib.php");
require_once($CFG->libdir . '/completionlib.php');

$id        = optional_param('id', 0, PARAM_INT);
$u         = optional_param('u', 0, PARAM_INT);
$redirect  = optional_param('redirect', 0, PARAM_BOOL);
$forceview = optional_param('forceview', 0, PARAM_BOOL);

if ($u) {
    $url = $DB->get_record('url', array('id' => $u), '*', MUST_EXIST);
    $cm  = get_coursemodule_from_instance('url', $url->id, $url->course, false, MUST_EXIST);
} else {
    $cm  = get_coursemodule_from_id('url', $id, 0, false, MUST_EXIST);
    $url = $DB->get_record('url', array('id' => $cm->instance), '*', MUST_EXIST);
}

$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/url:view', $context);

// Completion and trigger events.
url_view($url, $course, $cm, $context);

$PAGE->set_url('/mod/url/view.php', array('id' => $cm->id));

// Make sure URL exists before generating output
$exturl = trim($url->externalurl);
if (empty($exturl) or $exturl === 'http://') {
    $PAGE->activityheader->set_description(url_get_intro($url, $cm));
    url_print_header($url, $cm, $course);
    notice(get_string('invalidstoredurl', 'url'), new moodle_url('/course/view.php', array('id' => $cm->course)));
    die;
}
unset($exturl);

$displaytype = url_get_final_display_type($url);
if ($displaytype == RESOURCELIB_DISPLAY_OPEN) {
    $redirect = true;
}

if ($redirect && !$forceview) {
    $fullurl = str_replace('&amp;', '&', url_get_full_url($url, $cm, $course));

    if (!course_get_format($course)->has_view_page()) {
        $editurl = null;
        if (has_capability('moodle/course:manageactivities', $context)) {
            $editurl  = new moodle_url('/course/modedit.php', array('update' => $cm->id));
            $edittext = get_string('editthisactivity');
        } else if (has_capability('moodle/course:update', $context->get_course_context())) {
            $editurl  = new moodle_url('/course/edit.php', array('id' => $course->id));
            $edittext = get_string('editcoursesettings');
        }
        if ($editurl) {
            redirect($fullurl, html_writer::link($editurl, $edittext)."<br/>"
                    .get_string('pageshouldredirect'), 10);
        }
    }
    redirect($fullurl);
}

// ============================================================
// VIDEOTRANSCRIBER: verifica se existe transcrição ou inicia
// ============================================================
$vt_record = null;
try {
    $vt_record = $DB->get_record('local_videotranscriber', array('cmid' => $cm->id));
} catch (Exception $e) {
    // Tabela não existe ainda - plugin não instalado
    $vt_record = null;
}

// Fallback: se nunca foi transcrito, dispara agora em background
if ($vt_record === false || $vt_record === null) {
    $observer_file = $CFG->dirroot . '/local/videotranscriber/classes/observer.php';
    if (file_exists($observer_file)) {
        require_once($observer_file);
        if (class_exists('\local_videotranscriber\observer')) {
            $triggered = \local_videotranscriber\observer::trigger_transcription(
                $cm->id, $cm->course, $url->externalurl
            );
            if ($triggered) {
                try {
                    $vt_record = $DB->get_record('local_videotranscriber', array('cmid' => $cm->id));
                } catch (Exception $e) {
                    $vt_record = null;
                }
            }
        }
    }
}

// ============================================================
// Renderiza o conteúdo do módulo URL (player, link, embed...)
// As funções url_display_* já incluem header e footer do Moodle
// ============================================================
$PAGE->activityheader->set_description(url_get_intro($url, $cm));

// Injeta o painel de transcrição ANTES de chamar url_print_header
// guardando em buffer para inserir após o conteúdo
ob_start();

switch ($displaytype) {
    case RESOURCELIB_DISPLAY_EMBED:
        url_display_embed($url, $cm, $course);
        break;
    case RESOURCELIB_DISPLAY_FRAME:
        url_display_frame($url, $cm, $course);
        break;
    default:
        url_print_workaround($url, $cm, $course);
        break;
}

$page_output = ob_get_clean();

// Injeta o painel de transcrição antes do rodapé do Moodle
$vt_panel = vt_build_panel($vt_record, $cm, $CFG);
$page_output = str_replace('</body>', $vt_panel . '</body>', $page_output);

echo $page_output;

// ============================================================
// Função para montar o HTML do painel de transcrição
// ============================================================
function vt_build_panel($record, $cm, $CFG) {
    $status_url = (new moodle_url('/local/videotranscriber/ajax/status.php', array('cmid' => $cm->id)))->out(false);
    $tutor_url  = (new moodle_url('/local/videotranscriber/view.php', array('cmid' => $cm->id)))->out(false);
    $retry_url  = (new moodle_url('/mod/url/view.php', array('id' => $cm->id)))->out(false);

    $html  = '<div id="vt-panel" style="';
    $html .= 'margin:24px auto;max-width:560px;font-family:sans-serif;';
    $html .= 'border:1px solid #cfd8dc;border-radius:8px;padding:20px 24px;';
    $html .= 'background:#f5f7fa;text-align:center;">';

    if (!$record) {
        $html .= '<p style="color:#78909c;font-size:14px;">🎬 Nenhuma transcrição disponível para este recurso.</p>';

    } else if ($record->status === 'completed' && !empty($record->transcription)) {
        $html .= '<p style="color:#2e7d32;font-size:17px;font-weight:bold;margin-bottom:12px;">✅ Transcrição disponível!</p>';
        $html .= '<a href="' . htmlspecialchars($tutor_url) . '" class="btn btn-primary btn-lg" style="font-size:16px;padding:10px 24px;">🤖 Abrir Tutor IA</a>';

    } else if ($record->status === 'error') {
        $errmsg = htmlspecialchars($record->transcription ?? 'Erro desconhecido.');
        $html .= '<p style="color:#c62828;font-weight:bold;font-size:15px;">❌ Falha na transcrição</p>';
        $html .= '<p style="font-size:12px;color:#666;white-space:pre-wrap;text-align:left;">' . $errmsg . '</p>';
        $html .= '<a href="' . htmlspecialchars($retry_url) . '" class="btn btn-secondary" style="font-size:13px;">🔄 Tentar novamente</a>';

    } else {
        // Status: processing
        $raw_msg = !empty($record->transcription) ? $record->transcription : '[5%] Iniciando processo de transcrição...';
        
        $percent = 5;
        if (preg_match('/\[(\d+)%\]\s*(.*)/', $raw_msg, $matches)) {
            $percent    = (int)$matches[1];
            $status_msg = htmlspecialchars(trim($matches[2]));
        } else {
            $status_msg = htmlspecialchars($raw_msg);
        }

        $html .= '<p style="color:#1976d2;font-size:16px;font-weight:bold;margin-bottom:12px;">⏳ Processando transcrição com IA...</p>';
        $html .= '<p id="vt-status-msg" style="font-size:13px;color:#546e7a;margin-bottom:14px;">' . $status_msg . '</p>';
        $html .= '<div style="width:100%;height:14px;background:#e0e0e0;border-radius:7px;overflow:hidden;box-shadow:inset 0 1px 3px rgba(0,0,0,0.1);">';
        $html .= '<div id="vt-bar" style="width:' . $percent . '%;height:100%;background:linear-gradient(90deg,#42a5f5,#1e88e5);border-radius:7px;transition:width 0.4s ease;"></div>';
        $html .= '</div>';
        $html .= '<p id="vt-percent-lbl" style="font-size:11px;color:#1e88e5;font-weight:bold;margin-top:6px;">' . $percent . '%</p>';
        $html .= '<p style="font-size:11px;color:#90a4ae;margin-top:10px;">Este processo pode demorar alguns minutos dependendo do tamanho do vídeo.</p>';
        $html .= '<script>';
        $html .= '(function(){';
        $html .= 'var vtUrl=' . json_encode($status_url) . ';';
        $html .= 'function poll(){fetch(vtUrl).then(function(r){return r.json();}).then(function(data){';
        $html .= 'if(data.status==="completed"||data.status==="error"){location.reload();}';
        $html .= 'else if(data.transcription){';
        $html .= '  var msg = data.transcription;';
        $html .= '  var p = msg.match(/\[(\d+)%\]\s*(.*)/);';
        $html .= '  if(p){';
        $html .= '    document.getElementById("vt-bar").style.width = p[1] + "%";';
        $html .= '    document.getElementById("vt-percent-lbl").innerText = p[1] + "%";';
        $html .= '    msg = p[2];';
        $html .= '  }';
        $html .= '  var el=document.getElementById("vt-status-msg");if(el)el.innerText=msg;';
        $html .= '}';
        $html .= '}).catch(function(e){console.warn("VT poll",e);});}';
        $html .= 'setInterval(poll, 3000);';
        $html .= '})();';
        $html .= '</script>';
    }

    $html .= '</div>';
    return $html;
}
