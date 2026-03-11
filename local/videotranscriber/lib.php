<?php
/**
 * Local plugin library functions
 * @package local_videotranscriber
 */

defined('MOODLE_INTERNAL') || die();

// Este arquivo é obrigatório para que o Moodle reconheça o plugin.
// As funções de lógica principal estão em classes/observer.php e cli/run_transcription.php

/**
 * Extends the course navigation block.
 * Adds a link to the AI Tutors page for the course.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_videotranscriber_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('mod/url:view', $context)) {
        $url = new moodle_url('/local/videotranscriber/course_tutors.php', array('courseid' => $course->id));
        $navigation->add(
            '🤖 Tutor IA',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'videotranscriber_tutors',
            new pix_icon('i/course', 'Tutor IA')
        );
    }
}

/**
 * Extends general navigation and hooks JS to mod_url pages.
 */
function local_videotranscriber_extend_navigation(global_navigation $navigation) {
    global $PAGE, $DB;
    
    // A injeção do botão Tutor IA agora é feita direto no servidor via PHP 
    // dentro de mod/url/locallib.php, então não precisamos mais injetar JS aqui.
}
