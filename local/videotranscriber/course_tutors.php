<?php
/**
 * course_tutors.php — Lista todas as aulas (URL) do curso com acesso ao Tutor IA
 *
 * Acesso: /local/videotranscriber/course_tutors.php?courseid=ID
 *
 * @package local_videotranscriber
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('mod/url:view', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/videotranscriber/course_tutors.php', ['courseid' => $courseid]);
$PAGE->set_title('Tutor IA — ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// Busca todas as URLs do curso que foram transcritas
$sql = "
    SELECT
        cm.id as cmid,
        u.name as urlname,
        u.externalurl,
        vt.status,
        vt.transcription,
        vt.timemodified
    FROM {url} u
    JOIN {modules} m ON m.name = 'url'
    JOIN {course_modules} cm ON cm.instance = u.id AND cm.module = m.id AND cm.course = :courseid
    LEFT JOIN {local_videotranscriber} vt ON vt.cmid = cm.id
    ORDER BY cm.section, cm.id
";
$resources = $DB->get_records_sql($sql, ['courseid' => $courseid]);

echo $OUTPUT->header();

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

.vt-course-wrap {
    font-family: 'Inter', sans-serif;
    max-width: 860px;
    margin: 0 auto;
    padding: 8px 0 40px;
}

.vt-page-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 28px;
    padding-bottom: 18px;
    border-bottom: 2px solid #f0f0f0;
}

.vt-page-header .icon-wrap {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 14px;
    width: 52px; height: 52px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
}

.vt-page-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 4px;
}

.vt-page-header p {
    color: #6b7280;
    font-size: 14px;
    margin: 0;
}

.vt-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}

.vt-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: box-shadow 0.2s, transform 0.15s;
}

.vt-card:hover {
    box-shadow: 0 6px 18px rgba(99,102,241,0.12);
    transform: translateY(-2px);
}

.vt-card-title {
    font-size: 14.5px;
    font-weight: 600;
    color: #111827;
    line-height: 1.4;
}

.vt-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 20px;
    padding: 3px 10px;
}

.badge-done   { background: #d1fae5; color: #065f46; }
.badge-proc   { background: #dbeafe; color: #1e40af; }
.badge-err    { background: #fee2e2; color: #991b1b; }
.badge-none   { background: #f3f4f6; color: #6b7280; }

.vt-btn-tutor {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white !important;
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: opacity 0.18s;
    margin-top: auto;
}
.vt-btn-tutor:hover { opacity: 0.88; text-decoration: none; }

.vt-btn-video {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f3f4f6;
    color: #374151 !important;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s;
}
.vt-btn-video:hover { background: #e5e7eb; text-decoration: none; }

.vt-empty {
    text-align: center;
    color: #6b7280;
    padding: 60px 20px;
}

.vt-progress-mini {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}

.vt-progress-mini-bar {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    border-radius: 3px;
}
</style>

<div class="vt-course-wrap">

    <div class="vt-page-header">
        <div class="icon-wrap">🤖</div>
        <div>
            <h1>Tutor de IA — Aulas</h1>
            <p>Selecione uma aula abaixo para conversar com o Tutor de IA sobre o conteúdo do vídeo.</p>
        </div>
    </div>

    <?php if (empty($resources)): ?>
        <div class="vt-empty">
            <p style="font-size:48px;margin-bottom:8px;">📭</p>
            <p>Nenhuma aula com URL de vídeo foi encontrada neste curso.</p>
        </div>
    <?php else: ?>
    <div class="vt-grid">
    <?php foreach ($resources as $r): 
        $tutor_url = new moodle_url('/local/videotranscriber/view.php', ['cmid' => $r->cmid]);
        $video_url = new moodle_url('/mod/url/view.php', ['id'  => $r->cmid]);
        $status    = $r->status ?? 'none';

        // Extrai porcentagem da mensagem de status
        $percent = 0;
        if ($status === 'processing' && preg_match('/\[(\d+)%\]/', $r->transcription ?? '', $m)) {
            $percent = (int)$m[1];
        }

        if ($status === 'completed') {
            $badge = '<span class="vt-badge badge-done">✅ Transcrito</span>';
        } elseif ($status === 'processing') {
            $badge = '<span class="vt-badge badge-proc">⏳ Processando</span>';
        } elseif ($status === 'error') {
            $badge = '<span class="vt-badge badge-err">❌ Erro</span>';
        } else {
            $badge = '<span class="vt-badge badge-none">🎬 Pendente</span>';
        }
    ?>
    <div class="vt-card">
        <div class="vt-card-title"><?php echo format_string($r->urlname); ?></div>
        <?php echo $badge; ?>

        <?php if ($status === 'processing'): ?>
        <div class="vt-progress-mini">
            <div class="vt-progress-mini-bar" style="width:<?php echo $percent; ?>%"></div>
        </div>
        <?php endif; ?>

        <a href="<?php echo $video_url; ?>" class="vt-btn-video" target="_blank">
            🎬 Ver Aula
        </a>

        <?php if ($status === 'completed'): ?>
        <a href="<?php echo $tutor_url; ?>" class="vt-btn-tutor">
            🤖 Abrir Tutor IA
        </a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php echo $OUTPUT->footer(); ?>
