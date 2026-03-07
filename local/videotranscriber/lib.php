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
 * Library functions for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds an "Open AI Tutor" button in the footer of URL module pages.
 */
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
        'cmid' => $cmid,
    ]);

    echo html_writer::start_div('', ['style' => 'margin-top:20px']);
    echo html_writer::link(
        $url,
        get_string('opentutor', 'local_videotranscriber'),
        [
            'class' => 'btn btn-primary',
        ]
    );
    echo html_writer::end_div();
}
