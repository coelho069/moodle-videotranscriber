<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;
$url = $DB->get_record_sql("SELECT * FROM {url} ORDER BY id DESC LIMIT 1");

if ($url) {
    echo "Testing URL: " . $url->externalurl . "\n";
    $cm = get_coursemodule_from_instance('url', $url->id, $url->course, false, MUST_EXIST);
    
    // Explicitly delete any old records to force a fresh trigger
    $DB->delete_records('local_videotranscriber', ['cmid' => $cm->id]);
    
    require_once($CFG->dirroot . '/local/videotranscriber/classes/observer.php');
    $triggered = \local_videotranscriber\observer::trigger_transcription($cm->id, $cm->course, $url->externalurl);
    
    if ($triggered) {
        echo "MANUAL TRIGGER OK!\n";
        $record = $DB->get_record('local_videotranscriber', ['cmid' => $cm->id]);
        print_r($record);
        
        echo "\nNow trying to run Moodle Adhoc tasks to process the queue:\n";
        // Call Moodle's built in adhoc task runner (if available on this admin CLI)
        require_once($CFG->libdir . '/cronlib.php');
        ob_start();
        cron_run_adhoc_tasks(time());
        $output = ob_get_clean();
        echo "Adhoc runner output:\n" . $output;
        
    } else {
        echo "TRIGGER FAILED! The URL was caught by the regex/empty check.\n";
    }
}
