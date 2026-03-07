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
 * CLI script to split transcriptions into smaller chunks.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$maxlength = 3000; // Characters per chunk.

$records = $DB->get_records('local_videotranscriber');

foreach ($records as $r) {
    $text = trim((string) $r->transcription);
    if ($text === '') {
        continue;
    }

    // Delete existing chunks for this video before re-processing.
    $DB->delete_records('local_vt_chunks', ['videoid' => $r->id]);

    // Split by paragraph and group into chunks up to maxlength.
    $parts = preg_split("/\n{1,}/", $text);
    $chunks = [];
    $current = '';
    foreach ($parts as $p) {
        if (mb_strlen($current . "\n\n" . $p) <= $maxlength) {
            $current = ($current === '') ? $p : ($current . "\n\n" . $p);
        } else {
            if ($current !== '') {
                $chunks[] = $current;
            }
            $current = $p;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    // Insert chunks.
    $i = 0;
    foreach ($chunks as $c) {
        $DB->insert_record('local_vt_chunks', (object) [
            'videoid' => $r->id,
            'chunk_index' => $i++,
            'content' => $c,
        ]);
    }
    mtrace("Processed video id {$r->id}, chunks: " . count($chunks));
}
