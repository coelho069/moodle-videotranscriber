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

// ============================================================
// INJEÇÃO ROBUSTA DO PAINEL VT IA E CAPTURA DE TELA
// A maioria das resoluções do módulo URL usam die() ou exit() internamente
// Então registramos uma função ao encerramento do script para capturar 
// o output da página atual e costurar o HTML do Tutor no final.
// ============================================================
$GLOBALS['vt_record'] = $vt_record;
$GLOBALS['vt_cm'] = $cm;
$GLOBALS['vt_CFG'] = $CFG;

ob_start();

register_shutdown_function(function() {
    global $vt_record, $vt_cm, $vt_CFG;
    $page_output = ob_get_clean();
    
    // Constrói o HTML do Painel
    $panel = vt_build_panel($vt_record, $vt_cm, $vt_CFG);
    
    // Tenta injetar logo antes do final do container principal do Moodle ou do </body>
    if (strpos($page_output, '</body>') !== false) {
        $page_output = str_replace('</body>', $panel . '</body>', $page_output);
    } else {
        $page_output .= $panel;
    }
    
    echo $page_output;
});

switch ($displaytype) {
    case RESOURCELIB_DISPLAY_EMBED:
        url_display_embed($url, $cm, $course);
        break; // A função acima deve executar die() internamente.
    case RESOURCELIB_DISPLAY_FRAME:
        url_display_frame($url, $cm, $course);
        break; // A função acima deve executar die() internamente.
    default:
        url_print_workaround($url, $cm, $course);
        break; // A função acima deve executar die() internamente.
}

// ============================================================
// Função para montar o HTML do painel de transcrição
// ============================================================
function vt_build_panel($record, $cm, $CFG) {
    if (!isset($record)) return '';

    $status_url = (new moodle_url('/local/videotranscriber/ajax/status.php', array('cmid' => $cm->id)))->out(false);
    $retry_url  = (new moodle_url('/mod/url/view.php', array('id' => $cm->id)))->out(false);

    $html  = '<div id="vt-panel" style="';
    $html .= 'margin:24px auto;max-width:800px;font-family:sans-serif;';
    $html .= 'border:1px solid #cfd8dc;border-radius:8px;padding:20px 24px;';
    $html .= 'background:#f5f7fa;text-align:center;">';

    if (!$record) {
        $html .= '<p style="color:#78909c;font-size:14px;">🎬 Nenhuma transcrição disponível para este recurso.</p>';

    } else if ($record->status === 'completed' && !empty($record->transcription)) {
        // TELA DO IA TUTOR - INTERFACE MODERNA E SIMPLES (como pedido pelo usuário para funcionar!)
        $html .= '<h3 style="color:#2e7d32;font-size:18px;font-weight:bold;margin-top:0;margin-bottom:12px;text-align:left;">🤖 Tutor IA - Dúvidas da Aula</h3>';
        
        $ajax_tutor_url = (new moodle_url('/local/videotranscriber/ajax/tutor.php', array('cmid' => $cm->id)))->out(false);
        $sesskey = sesskey();

        $html .= '<div id="vt-chat-container" style="text-align:left; background:#fff; border:1px solid #cfd8dc; border-radius:8px; padding:16px;">';
        $html .= '<div id="vt-chat-log" style="max-height:300px; overflow-y:auto; margin-bottom:12px; font-size:14px; color:#333; display:none;"></div>';
        $html .= '<div style="display:flex; gap:8px;">';
        $html .= '<textarea id="vt-chat-input" placeholder="Pergunte algo sobre o vídeo..." style="flex:1; resize:none; border-radius:6px; border:1px solid #ccc; padding:10px 12px; font-family:inherit; font-size:14px; min-height:44px; outline:none;"></textarea>';
        $html .= '<button id="vt-chat-btn" style="background:#2e7d32; color:#fff; border:none; border-radius:6px; padding:0 20px; font-weight:bold; cursor:pointer;">Mandar</button>';
        $html .= '</div>';
        $html .= '<div id="vt-chat-loading" style="display:none; color:#2e7d32; font-size:12px; margin-top:8px; font-weight:bold;">⏳ Tutor está escrevendo...</div>';
        $html .= '</div>';
        
        $html .= '<script>';
        $html .= '(function(){';
        $html .= 'var btn=document.getElementById("vt-chat-btn");';
        $html .= 'var inp=document.getElementById("vt-chat-input");';
        $html .= 'var log=document.getElementById("vt-chat-log");';
        $html .= 'var load=document.getElementById("vt-chat-loading");';
            
        $html .= 'function askTutor() {';
        $html .= '  var q = inp.value.trim();';
        $html .= '  if(!q) return;';
        $html .= '  log.style.display="block";';
        $html .= '  log.innerHTML += "<div style=\'margin-bottom:12px;text-align:right;\'><span style=\'display:inline-block;background:#e8f5e9;color:#1b5e20;padding:10px 14px;border-radius:12px 12px 4px 12px;max-width:85%;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,0.05);\'>" + q.replace(/</g,"&lt;") + "</span></div>";';
        $html .= '  inp.value = ""; load.style.display="block"; btn.disabled=true; inp.disabled=true; btn.style.opacity="0.6";';
        $html .= '  log.scrollTop = log.scrollHeight;';
        
        $html .= '  fetch("'.$ajax_tutor_url.'", {';
        $html .= '    method: "POST", headers: {"Content-Type":"application/x-www-form-urlencoded"},';
        $html .= '    body: "sesskey='.$sesskey.'&question=" + encodeURIComponent(q)';
        $html .= '  }).then(r=>r.json()).then(data => {';
        $html .= '    load.style.display="none"; btn.disabled=false; inp.disabled=false; btn.style.opacity="1"; inp.focus();';
        $html .= '    var ans = data.answer ? data.answer.replace(/\\n/g, "<br>") : (data.error ? "❌ " + data.error : "❌ Erro inesperado.");';
        $html .= '    log.innerHTML += "<div style=\'margin-bottom:12px;text-align:left;\'><span style=\'display:inline-block;background:#f5f5f5;color:#111;padding:12px 16px;border-radius:12px 12px 12px 4px;border-left:4px solid #2e7d32;max-width:85%;box-shadow:0 1px 2px rgba(0,0,0,0.05);\'>" + ans + "</span></div>";';
        $html .= '    log.scrollTop = log.scrollHeight;';
        $html .= '  }).catch(e => {';
        $html .= '    load.style.display="none"; btn.disabled=false; inp.disabled=false; btn.style.opacity="1";';
        $html .= '    log.innerHTML += "<div style=\'color:#ef4444;margin-bottom:8px;text-align:left;\'>Erro ao conectar com servidor.</div>";';
        $html .= '  });';
        $html .= '}';
        $html .= 'btn.addEventListener("click", askTutor);';
        $html .= 'inp.addEventListener("keypress", function(e){ if(e.key==="Enter" && !e.shiftKey) { e.preventDefault(); askTutor(); } });';
        $html .= '})();';
        $html .= '</script>';

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
