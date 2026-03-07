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
 * AJAX endpoint to check transcription status.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_login();
require_sesskey();

global $DB;

header('Content-Type: application/json; charset=utf-8');

$cmid = required_param('cmid', PARAM_INT);

// Validate context and capabilities.
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
$context = context_module::instance($cmid);
require_capability('mod/url:view', $context);

$record = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);

if (!$record) {
    echo json_encode(['status' => 'none']);
    exit;
}

echo json_encode(['status' => $record->status]);
