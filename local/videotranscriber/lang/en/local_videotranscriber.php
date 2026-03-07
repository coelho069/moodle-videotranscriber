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
 * English language strings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Tutor (Video Transcription)';
$string['notranscription'] = 'Transcription for this video is not ready yet or was not found.';
$string['opentutor'] = 'Open AI Tutor';
$string['tutortitle'] = 'AI Tutor';
$string['tutorheading'] = 'AI Tutor: {$a}';
$string['tutordescription'] = 'Ask questions about the video content. The AI Tutor will answer based on the transcription.';
$string['yourquestion'] = 'Your question';
$string['questionplaceholder'] = 'Type your question about the video...';
$string['submitquestion'] = 'Submit question';
$string['tutoranswer'] = 'AI Tutor Response';
$string['backtoactivity'] = '← Back to activity';
$string['backtocourse'] = '← Back to course';
$string['transcription_unavailable'] = 'Transcription Unavailable';
$string['transcription_unavailable_desc'] = 'The transcription for this video has not been completed yet or the record was not found. Processing usually takes a few minutes. Please wait and reload the page.';
$string['settings_apikey'] = 'OpenAI API Key';
$string['settings_apikey_desc'] = 'Enter your OpenAI API key to enable the AI Tutor feature.';
$string['error'] = 'Error:';
$string['error_apikey'] = 'API key not configured.';
$string['error_emptyquestion'] = 'Empty question.';
$string['error_emptyresponse'] = 'Empty response from AI.';
$string['error_json'] = 'Error generating JSON payload.';
$string['error_connection'] = 'Connection error.';
$string['error_invalidresponse'] = 'Invalid response from OpenAI.';
$string['error_unknown'] = 'Unknown error.';
$string['error_api'] = 'API error: {$a}';
