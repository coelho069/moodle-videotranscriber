<?php
/**
 * view.php — Tutor de IA baseado na transcrição do vídeo
 *
 * Permite ao aluno fazer perguntas sobre o conteúdo do vídeo à IA.
 *
 * @package local_videotranscriber
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('url', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$url_mod = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
/** @var \context $context */
$context = context_module::instance($cmid);

$PAGE->set_context($context);
$PAGE->set_url('/local/videotranscriber/view.php', ['cmid' => $cmid]);
$PAGE->set_title('Tutor IA — ' . format_string($url_mod->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->jquery();

// Busca a transcrição no banco
$vt_record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);

echo $OUTPUT->header();

if (!$vt_record || $vt_record->status !== 'completed' || empty($vt_record->transcription)) {
    echo $OUTPUT->notification(
        '⚠️ A transcrição deste vídeo ainda não está disponível. Aguarde o processamento.',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

$transcription = $vt_record->transcription;
$video_name    = format_string($url_mod->name);
$apikey        = get_config('local_videotranscriber', 'openaiapikey');

if (empty($apikey)) {
    echo $OUTPUT->notification('⚠️ A API Key da OpenAI não está configurada.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// --- Processa a pergunta do aluno (POST) ---
$ai_response = '';
$user_question = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $user_question = clean_param($_POST['question'], PARAM_TEXT);

    // Monta o prompt para a API da OpenAI
    $system_prompt = "Você é um tutor de IA especializado no conteúdo do seguinte vídeo: \"$video_name\".\n" .
        "Responda APENAS com base na transcrição abaixo. " .
        "Não invente informações. Se a pergunta não puder ser respondida com o conteúdo do vídeo, diga isso claramente.\n\n" .
        "=== TRANSCRIÇÃO DO VÍDEO ===\n" . mb_substr($transcription, 0, 12000) . "\n=== FIM DA TRANSCRIÇÃO ===";

    $payload = json_encode([
        'model'    => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system',  'content' => $system_prompt],
            ['role' => 'user',    'content' => $user_question],
        ],
        'max_tokens'  => 1024,
        'temperature' => 0.3,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    $ai_response = $data['choices'][0]['message']['content'] ?? '❌ Erro ao obter resposta da IA. Tente novamente.';
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

#vt-tutor-wrap {
    font-family: 'Inter', sans-serif;
    max-width: 800px;
    margin: 24px auto;
}

#vt-tutor-wrap h2 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 4px;
}

#vt-tutor-wrap .subtitle {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 24px;
}

.vt-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 24px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    margin-bottom: 20px;
}

.vt-transcript-box {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    max-height: 220px;
    overflow-y: auto;
    font-size: 13.5px;
    color: #374151;
    line-height: 1.7;
    white-space: pre-wrap;
    margin-bottom: 0;
}

.vt-label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    display: block;
}

.vt-input-wrap {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

#vt-question {
    flex: 1;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    resize: vertical;
    min-height: 80px;
    transition: border-color 0.2s;
    outline: none;
    color: #111;
}
#vt-question:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}

#vt-submit {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 22px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
    transition: opacity 0.2s, transform 0.1s;
    white-space: nowrap;
}
#vt-submit:hover { opacity: 0.9; transform: translateY(-1px); }
#vt-submit:active { transform: translateY(0); }

.vt-question-bubble {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-radius: 12px 12px 4px 12px;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 16px;
    max-width: 85%;
    margin-left: auto;
}

.vt-answer-bubble {
    background: #f3f4f6;
    color: #111827;
    border-radius: 12px 12px 12px 4px;
    padding: 14px 18px;
    font-size: 14px;
    line-height: 1.75;
    white-space: pre-wrap;
    border-left: 4px solid #6366f1;
}

.vt-tag {
    display: inline-block;
    background: #ede9fe;
    color: #7c3aed;
    border-radius: 20px;
    padding: 3px 12px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 12px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #6366f1;
    text-decoration: none;
    margin-bottom: 16px;
    font-weight: 500;
}
.back-link:hover { text-decoration: underline; }
</style>

<div id="vt-tutor-wrap">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <a href="<?php echo new moodle_url('/mod/url/view.php', ['id' => $cmid]); ?>" class="back-link" style="margin-bottom: 0;">
            ← Voltar para o vídeo
        </a>
        <a href="<?php echo new moodle_url('/local/videotranscriber/course_tutors.php', ['courseid' => $course->id]); ?>" class="back-link" style="margin-bottom: 0; color: #4b5563;">
            📚 Todas as aulas do curso
        </a>
    </div>

    <h2>🤖 Tutor de IA</h2>
    <p class="subtitle">Baseado no conteúdo do vídeo: <strong><?php echo $video_name; ?></strong></p>

    <!-- Transcrição -->
    <div class="vt-card">
        <span class="vt-label">📄 Transcrição do Vídeo</span>
        <div class="vt-transcript-box"><?php echo nl2br(htmlspecialchars($transcription)); ?></div>
    </div>

    <?php if ($user_question && $ai_response): ?>
    <!-- Conversa -->
    <div class="vt-card">
        <span class="vt-tag">💬 Conversa</span>
        <div class="vt-question-bubble"><?php echo htmlspecialchars($user_question); ?></div>
        <div class="vt-answer-bubble"><?php echo htmlspecialchars($ai_response); ?></div>
    </div>
    <?php endif; ?>

    <!-- Formulário de pergunta -->
    <div class="vt-card">
        <form method="POST" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <span class="vt-label">💡 Faça uma pergunta sobre o vídeo</span>
            <div class="vt-input-wrap">
                <textarea name="question" id="vt-question" placeholder="Ex: Qual é o principal tema abordado no vídeo?" required><?php echo htmlspecialchars($user_question); ?></textarea>
                <button type="submit" id="vt-submit">Perguntar →</button>
            </div>
        </form>
    </div>
</div>

<?php echo $OUTPUT->footer(); ?>
