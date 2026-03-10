<?php
/**
 * Settings page for local_videotranscriber
 * @package local_videotranscriber
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videotranscriber', get_string('pluginname', 'local_videotranscriber'));

    // API Key
    $settings->add(new admin_setting_configpasswordunmask(
        'local_videotranscriber/openaiapikey',
        get_string('openaiapikey', 'local_videotranscriber'),
        get_string('openaiapikey_desc', 'local_videotranscriber'),
        ''
    ));

    // API Base URL
    $settings->add(new admin_setting_configtext(
        'local_videotranscriber/apiurl',
        get_string('apiurl', 'local_videotranscriber'),
        get_string('apiurl_desc', 'local_videotranscriber'),
        'https://api.openai.com/v1'
    ));

    // Chat Model
    $settings->add(new admin_setting_configtext(
        'local_videotranscriber/chatmodel',
        get_string('chatmodel', 'local_videotranscriber'),
        get_string('chatmodel_desc', 'local_videotranscriber'),
        'gpt-4o-mini'
    ));

    // Transcription Model
    $settings->add(new admin_setting_configtext(
        'local_videotranscriber/transcribemodel',
        get_string('transcribemodel', 'local_videotranscriber'),
        get_string('transcribemodel_desc', 'local_videotranscriber'),
        'whisper-1'
    ));

    $ADMIN->add('localplugins', $settings);
}
