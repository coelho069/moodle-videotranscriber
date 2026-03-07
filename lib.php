<?php

defined('MOODLE_INTERNAL') || die();

function local_videotranscriber_before_footer() {
    global $PAGE;

    if (!$PAGE->cm) {
        return;
    }

    if ($PAGE->cm->modname !== 'url') {
        return;
    }

    $cmid = $PAGE->cm->id;

    $url = new moodle_url('/local/videotranscriber/view.php', [
        'cmid' => $cmid
    ]);

    echo '<div style="margin-top:20px">
            <a href="'.$url.'" style="
                display:inline-block;
                background:#2563eb;
                color:#fff;
                padding:10px 18px;
                border-radius:6px;
                text-decoration:none;
                font-weight:bold;
            ">
            Abrir Tutor IA
            </a>
          </div>';
}
