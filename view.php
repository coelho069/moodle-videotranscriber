<?php
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
        echo json_encode(['success' => false, 'error' => 'A transcrição para este vídeo ainda não está pronta.']);
        exit;
    }
    
    $PAGE->set_url('/local/videotranscriber/view.php', ['cmid' => $cmid]);
    $PAGE->set_context($context);
    $PAGE->set_course($course);
    $PAGE->set_cm($cm);
    $PAGE->set_title('Tutor IA: ' . $cm->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_pagelayout('incourse');
    
    echo $OUTPUT->header();
    
    echo html_writer::start_div('card shadow-sm mb-4 mt-4');
    echo html_writer::start_div('card-header bg-warning text-dark');
    echo html_writer::tag('h4', '⏳ Transcrição Indisponível', ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::start_div('card-body');
    echo html_writer::tag('p', 'A transcrição para este vídeo ainda não foi concluída ou o registro não foi encontrado. O processamento geralmente leva alguns minutos. Por favor, aguarde e recarregue a página.', ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('mt-4');
    echo html_writer::link(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        '← Voltar ao curso',
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
    echo html_writer::end_div();
    
    echo $OUTPUT->footer();
    exit;
}

// AJAX - processa pergunta.
if ($action === 'ask' && !empty($question)) {
    require_sesskey();
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');

    $apikey = get_config('local_videotranscriber', 'openai_api_key');
    if (empty($apikey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'API key nao configurada.']);
        exit;
    }

    // === PROCESSAR ENTRADA ===
    $transcription = $record->transcription ?? '';
    
    // Remove BOM
    $transcription = preg_replace('/^\xEF\xBB\xBF/', '', $transcription);
    
    // Remove caracteres de controle
    $transcription = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $transcription);
    
    // Normaliza quebras de linha
    $transcription = preg_replace('/\r\n|\r/', "\n", $transcription);
    $transcription = preg_replace('/\n\n+/', "\n", $transcription);
    
    // UTF-8
    if (!mb_check_encoding($transcription, 'UTF-8')) {
        $transcription = mb_convert_encoding($transcription, 'UTF-8', 'UTF-8');
    }
    
    $transcription = mb_substr($transcription, 0, 10000);
    $transcription = trim($transcription);

    $question = trim($question);
    $question = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question);
    $question = preg_replace('/\r\n|\r/', "\n", $question);
    
    if (!mb_check_encoding($question, 'UTF-8')) {
        $question = mb_convert_encoding($question, 'UTF-8', 'UTF-8');
    }
    
    $question = trim($question);
    
    if (strlen($question) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Pergunta vazia']);
        exit;
    }

    // === CRIAR MENSAGENS ===
    $system_msg = "ATENCAO MAXIMA: Voce e um assistente educacional estritamente limitado ao texto fornecido.\n"
                . "REGRA 1: baseie-se EXCLUSIVAMENTE nas palavras da transcricao do video abaixo.\n"
                . "REGRA 2: E expressamente PROIBIDO inventar informacao, deduzir coisas obvias ou usar seu conhecimento previo.\n"
                . "REGRA 3: Se a transcricao nao contiver a resposta exata para a pergunta do aluno, VOCE DEVE RESPONDER EXATAMENTE O SEGUINTE: 'Sinto muito, mas essa informacao nao foi mencionada no video.'\n\n"
                . "Responda de forma clara, amigavel e em portugues do Brasil.\n\n"
                . "=== TRANSCRICAO ===\n"
                . $transcription
                . "\n=== FIM DA TRANSCRICAO ===";

    // === PAYLOAD ===
    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 1000,
        'temperature' => 0.3,
        'messages'    => [
            [
                'role'    => 'system',
                'content' => $system_msg
            ],
            [
                'role'    => 'user',
                'content' => $question
            ]
        ]
    ];

    // === GERAR JSON ===
    $json_payload = json_encode($payload);
    
    if ($json_payload === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao gerar JSON: ' . json_last_error_msg()]);
        exit;
    }

    // === ENVIAR COM file_get_contents (MAIS SEGURO QUE CURL) ===
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apikey . "\r\n",
            'content' => $json_payload,
            'timeout' => 60,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);

    if ($response === false) {
        $error = error_get_last();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexao: ' . ($error['message'] ?? 'Desconhecido')]);
        exit;
    }

    // === PROCESSAR RESPOSTA ===
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Resposta invalida da OpenAI']);
        exit;
    }

    // Verificar se há erro na resposta
    if (isset($data['error'])) {
        $error_msg = isset($data['error']['message']) 
            ? $data['error']['message']
            : 'Erro desconhecido';
        
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'OpenAI: ' . $error_msg]);
        exit;
    }

    // Extrair resposta
    if (!isset($data['choices'][0]['message']['content'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Resposta inesperada da API']);
        exit;
    }

    $answer = trim($data['choices'][0]['message']['content']);

    // === SUCESSO ===
    http_response_code(200);
    echo json_encode(['success' => true, 'answer' => $answer]);
    exit;
}

// ===== PAGINA NORMAL =====

$PAGE->set_url('/local/videotranscriber/view.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Tutor IA: ' . $cm->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

echo html_writer::start_div('card shadow-sm mb-4');
echo html_writer::start_div('card-header bg-primary text-white');
echo html_writer::tag('h4', '🤖 Tutor IA: ' . format_string($cm->name), ['class' => 'mb-0']);
echo html_writer::end_div();
echo html_writer::start_div('card-body');
echo html_writer::tag('p',
    'Faca perguntas sobre o conteudo do video. O Tutor IA respondera com base na transcricao.',
    ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card shadow-sm mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', 'Sua pergunta', ['class' => 'card-title']);
echo html_writer::tag('textarea', '', [
    'id'          => 'vt-question',
    'class'       => 'form-control mb-3',
    'rows'        => 4,
    'placeholder' => 'Digite sua pergunta sobre o video...',
    'maxlength'   => 2000,
]);
echo html_writer::start_div('d-flex align-items-center gap-3');
echo html_writer::tag('button', 'Enviar pergunta', [
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
echo html_writer::tag('strong', '🧠 Resposta do Tutor IA');
echo html_writer::end_div();
echo html_writer::start_div('card-body');
echo html_writer::tag('div', '', ['id' => 'vt-answer', 'class' => 'lh-lg']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('alert alert-danger d-none mt-3', ['id' => 'vt-error', 'role' => 'alert']);
echo html_writer::tag('strong', 'Erro: ');
echo html_writer::tag('span', '', ['id' => 'vt-error-msg']);
echo html_writer::end_div();

echo html_writer::start_div('mt-4');
echo html_writer::link(
    new moodle_url('/mod/url/view.php', ['id' => $cmid]),
    '← Voltar a atividade',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::end_div();

$sesskey = sesskey();
$ask_url = (new moodle_url('/local/videotranscriber/view.php', [
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
    var askUrl     = ' . json_encode($ask_url) . ';

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
                showError(data.error || "Erro desconhecido");
            }
        })
        .catch(function(e) {
            var msg = (typeof e === "string") ? e : (e.error || e.message || "Erro desconhecido");
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
