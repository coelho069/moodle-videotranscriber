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
 * URL module main user interface
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
    $url = $DB->get_record('url', ['id' => $u], '*', MUST_EXIST);
    $cm  = get_coursemodule_from_instance('url', $url->id, $url->course, false, MUST_EXIST);
} else {
    $cm  = get_coursemodule_from_id('url', $id, 0, false, MUST_EXIST);
    $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
}

$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/url:view', $context);

url_view($url, $course, $cm, $context);

$PAGE->set_url('/mod/url/view.php', ['id' => $cm->id]);

$exturl = trim($url->externalurl);
if (empty($exturl) || $exturl === 'http://') {
    $PAGE->activityheader->set_description(url_get_intro($url, $cm));
    url_print_header($url, $cm, $course);
    notice(get_string('invalidstoredurl', 'url'), new moodle_url('/course/view.php', ['id' => $cm->course]));
    die;
}

$displaytype = url_get_final_display_type($url);
if ($displaytype == RESOURCELIB_DISPLAY_OPEN) {
    $redirect = true;
}

if ($redirect && !$forceview) {
    $fullurl = str_replace('&amp;', '&', url_get_full_url($url, $cm, $course));

    if (!course_get_format($course)->has_view_page()) {
        $editurl = null;
        if (has_capability('moodle/course:manageactivities', $context)) {
            $editurl  = new moodle_url('/course/modedit.php', ['update' => $cm->id]);
            $edittext = get_string('editthisactivity');
        } else if (has_capability('moodle/course:update', $context->get_course_context())) {
            $editurl  = new moodle_url('/course/edit.php', ['id' => $course->id]);
            $edittext = get_string('editcoursesettings');
        }
        if ($editurl) {
            redirect($fullurl, html_writer::link($editurl, $edittext) . "<br/>" . get_string('pageshouldredirect'), 10);
        }
    }
    redirect($fullurl);
}

// ============================================================
// VIDEOTRANSCRIBER: verifica / inicia a transcrição
// ============================================================
$record = $DB->get_record('local_videotranscriber', ['cmid' => $cm->id]);

// Fallback: se nunca foi transcrito, dispara agora em background
if (!$record) {
    require_once($CFG->dirroot . '/local/videotranscriber/classes/observer.php');
    $triggered = \local_videotranscriber\observer::trigger_transcription($cm->id, $cm->course, $url->externalurl);
    if ($triggered) {
        // Lê o registro recém-criado para mostrar o estado "processing"
        $record = $DB->get_record('local_videotranscriber', ['cmid' => $cm->id]);
    }
}

// ============================================================
// Renderiza a página normalmente (vídeo embed/frame/workaround)
// ============================================================
$PAGE->activityheader->set_description(url_get_intro($url, $cm));
url_print_header($url, $cm, $course);

// Renderiza o player/link do vídeo
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

// ============================================================
// PAINEL DE TRANSCRIÇÃO / BARRA DE PROGRESSO
// ============================================================
$status_url = (new moodle_url('/local/videotranscriber/ajax/status.php', ['cmid' => $cm->id]))->out(false);
$tutor_url  = (new moodle_url('/local/videotranscriber/view.php', ['cmid' => $cm->id]))->out(false);

echo <<<HTML
<div id="vt-panel" style="
    margin: 24px auto;
    max-width: 560px;
    font-family: sans-serif;
    border: 1px solid #cfd8dc;
    border-radius: 8px;
    padding: 20px 24px;
    background: #f5f7fa;
    text-align: center;
">
HTML;

if (!$record) {
    // URL não é um vídeo suportado
    echo '<p style="color:#78909c;font-size:14px;">🎬 Este link não tem vídeotranscrição agendada.</p>';

} else if ($record->status === 'completed' && !empty($record->transcription)) {
    echo '<p style="color:#2e7d32;font-size:17px;font-weight:bold;margin-bottom:12px;">✅ Transcrição disponível!</p>';
    echo '<a href="' . htmlspecialchars($tutor_url) . '" class="btn btn-primary btn-lg">🤖 Abrir Tutor IA</a>';

} else if ($record->status === 'error') {
    $errmsg = htmlspecialchars($record->transcription ?? '');
    echo '<p style="color:#c62828;font-weight:bold;font-size:15px;">❌ Falha na transcrição</p>';
    echo '<p style="font-size:12px;color:#666;white-space:pre-wrap;">' . $errmsg . '</p>';
    echo '<a href="?id=' . (int)$cm->id . '" style="font-size:13px;">🔄 Tentar novamente</a>';

} else {
    // Status: processing
    $status_msg = !empty($record->transcription) ? htmlspecialchars($record->transcription) : 'Iniciando processo de transcrição...';
    echo <<<HTML
    <p style="color:#1976d2;font-size:16px;font-weight:bold;margin-bottom:12px;">⏳ Processando transcrição com IA...</p>
    <p id="vt-status-msg" style="font-size:13px;color:#546e7a;margin-bottom:14px;">{$status_msg}</p>
    <div style="
        width:100%;
        height:12px;
        background:#cfd8dc;
        border-radius:6px;
        overflow:hidden;
    ">
        <div id="vt-bar" style="
            width:100%;
            height:100%;
            background: linear-gradient(90deg,#42a5f5,#1565c0);
            animation: vt-slide 1.8s ease-in-out infinite alternate;
        "></div>
    </div>
    <p style="font-size:11px;color:#90a4ae;margin-top:10px;">
        Este processo pode demorar alguns minutos dependendo do tamanho do vídeo.
    </p>
    <style>
        @keyframes vt-slide {
            0%   { transform: translateX(-30%) scaleX(0.4); }
            100% { transform: translateX(0%)   scaleX(1);   }
        }
    </style>
    <script>
        (function() {
            var url = "<?php echo $status_url; ?>";
            function poll() {
                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.status === 'completed' || data.status === 'error') {
                            location.reload();
                        } else if (data.transcription) {
                            var el = document.getElementById('vt-status-msg');
                            if (el) el.innerText = data.transcription;
                        }
                    })
                    .catch(function(e) { console.warn('VT poll error', e); });
            }
            setInterval(poll, 4000);
        })();
    </script>
HTML;
}

echo '</div>';

// Rodapé Moodle
echo $OUTPUT->footer();
