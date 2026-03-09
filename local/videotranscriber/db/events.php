<?php
// This file registers the event observers for the local_videotranscriber plugin.

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\course_module_created',
        'callback'    => '\local_videotranscriber\observer::course_module_created',
        'internal'    => false, // Permitir adhoc task/cron
    ],
    [
        'eventname'   => '\core\event\course_module_updated',
        'callback'    => '\local_videotranscriber\observer::course_module_updated',
        'internal'    => false,
    ]
];
