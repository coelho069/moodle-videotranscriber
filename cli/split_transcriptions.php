<?php
// CLI: php local/videotranscriber/cli/split_transcriptions.php
define('CLI_SCRIPT', true);
require('../../../config.php');

global $DB;

$maxlength = 3000; // caracteres por chunk

$records = $DB->get_records('local_videotranscriber');

foreach ($records as $r) {
    $text = trim((string)$r->transcription);
    if ($text === '') continue;
    // simples split por paragrafo até atingir maxlength
    $parts = preg_split("/\n{1,}/", $text);
    $chunks = [];
    $current = '';
    $idx = 0;
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
    if ($current !== '') $chunks[] = $current;

    // gravar chunks
    $i = 0;
    foreach ($chunks as $c) {
        $DB->insert_record('local_vt_chunks', (object)[
            'videoid' => $r->id,
            'chunk_index' => $i++,
            'content' => $c
        ]);
    }
    echo "Processed video id {$r->id}, chunks " . count($chunks) . PHP_EOL;
}
