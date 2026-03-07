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
 * AI Tutor chat interface for video transcriptions.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', isset($_POST['action']) && $_POST['action'] === 'ask');
require_once(__DIR__ . '/../../config.php');

$cmid     = required_param('cmid', PARAM_INT);
$question = optional_param('question', '', PARAM_TEXT);
$action   = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, true, $cm);
$context = context_module::instance($cmid);
require_capability('mod/url:view', $context);

$record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);

if (!$record || empty($record->transcription)) {
    if ($action === 'ask') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => get_string('notranscription', 'local_videotranscriber')]);
        exit;
    }

    $PAGE->set_url('/local/videotranscriber/view.php', ['cmid' => $cmid]);
    $PAGE->set_context($context);
    $PAGE->set_course($course);
    $PAGE->set_cm($cm);
    $PAGE->set_title(get_string('tutortitle', 'local_videotranscriber') . ': ' . format_string($cm->name));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_pagelayout('incourse');

    echo $OUTPUT->header();

    echo html_writer::start_div('card shadow-sm mb-4 mt-4');
    echo html_writer::start_div('card-header bg-warning text-dark');
    echo html_writer::tag('h4', get_string('transcription_unavailable', 'local_videotranscriber'), ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::start_div('card-body');
    echo html_writer::tag('p', get_string('transcription_unavailable_desc', 'local_videotranscriber'), ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('mt-4');
    echo html_writer::link(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('backtocourse', 'local_videotranscriber'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
    echo html_writer::end_div();

    echo $OUTPUT->footer();
    exit;
}

// AJAX - process question.
if ($action === 'ask' && !empty($question)) {
    require_sesskey();

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    $apikey = get_config('local_videotranscriber', 'openai_api_key');
    if (empty($apikey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => get_string('error_apikey', 'local_videotranscriber')]);
        exit;
    }

    // Process transcription input.
    $transcription = $record->transcription ?? '';

    // Remove BOM.
    $transcription = preg_replace('/^\xEF\xBB\xBF/', '', $transcription);

    // Remove control characters.
    $transcription = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $transcription);

    // Normalize line breaks.
    $transcription = preg_replace('/\r\n|\r/', "\n", $transcription);
    $transcription = preg_replace('/\n\n+/', "\n", $transcription);

    // Ensure valid UTF-8.
    if (!mb_check_encoding($transcription, 'UTF-8')) {
        $transcription = mb_convert_encoding($transcription, 'UTF-8', 'UTF-8');
    }

    $transcription = mb_substr($transcription, 0, 10000);
    $transcription = trim($transcription);

    // Sanitize question input.
    $question = trim($question);
    $question = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question);
    $question = preg_replace('/\r\n|\r/', "\n", $question);

    if (!mb_check_encoding($question, 'UTF-8')) {
        $question = mb_convert_encoding($question, 'UTF-8', 'UTF-8');
    }

    $question = trim($question);

    if (strlen($question) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => get_string('error_emptyquestion', 'local_videotranscriber')]);
        exit;
    }

    // Build messages for the API.
    $systemmsg = "You are an educational tutor. Use ONLY the transcription below to answer.\n"
               . "If the answer is not in the transcription, say so clearly.\n"
               . "Answer in a clear and didactic manner.\n\n"
               . "=== TRANSCRIPTION ===\n"
               . $transcription
               . "\n=== END TRANSCRIPTION ===";

    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 1000,
        'temperature' => 0.3,
        'messages'    => [
            [
                'role'    => 'system',
                'content' => $systemmsg,
            ],
            [
                'role'    => 'user',
                'content' => $question,
            ],
        ],
    ];

    // Generate JSON payload.
    $jsonpayload = json_encode($payload);

    if ($jsonpayload === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => get_string('error_json', 'local_videotranscriber')]);
        exit;
    }

    // Send request using cURL.
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonpayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curlerr = curl_error($ch);
    $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => get_string('error_connection', 'local_videotranscriber')]);
        exit;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => get_string('error_invalidresponse', 'local_videotranscriber')]);
        exit;
    }

    // Check for API error response.
    if (isset($data['error'])) {
        $errormsg = $data['error']['message'] ?? get_string('error_unknown', 'local_videotranscriber');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'OpenAI: ' . $errormsg]);
        exit;
    }

    // Extract answer.
    if (!isset($data['choices'][0]['message']['content'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => get_string('error_invalidresponse', 'local_videotranscriber')]);
        exit;
    }

    $answer = trim($data['choices'][0]['message']['content']);

    http_response_code(200);
    echo json_encode(['success' => true, 'answer' => $answer]);
    exit;
}

// Normal page display.
$PAGE->set_url('/local/videotranscriber/view.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('tutortitle', 'local_videotranscriber') . ': ' . format_string($cm->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

echo html_writer::start_div('card shadow-sm mb-4');
echo html_writer::start_div('card-header bg-primary text-white');
echo html_writer::tag('h4', get_string('tutorheading', 'local_videotranscriber', format_string($cm->name)), ['class' => 'mb-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');
echo html_writer::tag('p', get_string('tutordescription', 'local_videotranscriber'), ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card shadow-sm mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('yourquestion', 'local_videotranscriber'), ['class' => 'card-title']);
echo html_writer::tag('textarea', '', [
    'id'          => 'vt-question',
    'class'       => 'form-control mb-3',
    'rows'        => 4,
    'placeholder' => get_string('questionplaceholder', 'local_videotranscriber'),
    'maxlength'   => 2000,
]);
echo html_writer::start_div('d-flex align-items-center gap-3');
echo html_writer::tag('button', get_string('submitquestion', 'local_videotranscriber'), [
    'id'    => 'vt-submit',
    'class' => 'btn btn-primary',
    'type'  => 'button',
]);
echo html_writer::start_div('spinner-border text-primary d-none', [
    'id'   => 'vt-spinner',
    'role' => 'status',
]);
echo html_writer::tag('span', '', ['class' => 'visually-hidden']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card shadow-sm d-none mb-4', ['id' => 'vt-answer-card']);
echo html_writer::start_div('card-header bg-success text-white');
echo html_writer::tag('strong', get_string('tutoranswer', 'local_videotranscriber'));
echo html_writer::end_div();
echo html_writer::start_div('card-body');
echo html_writer::tag('div', '', ['id' => 'vt-answer', 'class' => 'lh-lg']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('alert alert-danger d-none mt-3', ['id' => 'vt-error', 'role' => 'alert']);
echo html_writer::tag('strong', get_string('error', 'local_videotranscriber') . ' ');
echo html_writer::tag('span', '', ['id' => 'vt-error-msg']);
echo html_writer::end_div();

echo html_writer::start_div('mt-4');
echo html_writer::link(
    new moodle_url('/mod/url/view.php', ['id' => $cmid]),
    get_string('backtoactivity', 'local_videotranscriber'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::end_div();

$sesskey = sesskey();
$askurl = (new moodle_url('/local/videotranscriber/view.php', [
    'cmid'    => $cmid,
    'action'  => 'ask',
    'sesskey' => $sesskey,
]))->out(false);

$js = '<script>
(function() {
    "use strict";

    var submitBtn  = document.getElementById("vt-submit");
    var inputBox   = document.getElementById("vt-question");
    var spinner    = document.getElementById("vt-spinner");
    var answerCard = document.getElementById("vt-answer-card");
    var answerDiv  = document.getElementById("vt-answer");
    var errorBox   = document.getElementById("vt-error");
    var errorMsg   = document.getElementById("vt-error-msg");
    var askUrl     = ' . json_encode($askurl) . ';

    function formatText(text) {
        var t = text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
        t = t.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
        t = t.replace(/\*([^*]+)\*/g, "<em>$1</em>");
        t = t.replace(/\n/g, "<br>");
        return t;
    }

    function setLoading(loading) {
        submitBtn.disabled = loading;
        spinner.classList.toggle("d-none", !loading);
    }

    function showError(msg) {
        errorBox.classList.remove("d-none");
        errorMsg.textContent = msg;
        answerCard.classList.add("d-none");
    }

    function showAnswer(html) {
        errorBox.classList.add("d-none");
        answerCard.classList.remove("d-none");
        answerDiv.innerHTML = html;
        answerCard.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    submitBtn.addEventListener("click", function() {
        var q = inputBox.value.trim();
        if (!q) {
            inputBox.focus();
            inputBox.classList.add("is-invalid");
            return;
        }
        inputBox.classList.remove("is-invalid");
        setLoading(true);
        errorBox.classList.add("d-none");

        fetch(askUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
            },
            body: "question=" + encodeURIComponent(q),
            credentials: "same-origin"
        })
        .then(function(r) {
            return r.json().then(function(data) {
                if (!r.ok) throw data;
                return data;
            });
        })
        .then(function(data) {
            if (data.success) {
                showAnswer(formatText(data.answer));
            } else {
                showError(data.error || "Unknown error");
            }
        })
        .catch(function(e) {
            var msg = (typeof e === "string") ? e : (e.error || e.message || "Unknown error");
            showError(msg);
        })
        .finally(function() {
            setLoading(false);
        });
    });

    inputBox.addEventListener("keydown", function(e) {
        if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) {
            submitBtn.click();
        }
    });
})();
</script>';

echo $js;
echo $OUTPUT->footer();
