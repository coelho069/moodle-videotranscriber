<?php
require('../../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$record = $DB->get_record('local_videotranscriber', ['cmid'=>$cmid]);
if (!$record) {
    echo json_encode(['status'=>'none']);
    exit;
}
echo json_encode(['status'=>$record->status, 'transcription'=>$record->transcription]);
