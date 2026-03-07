<?php
/**
 * Settings page for local_videotranscriber
 * @package local_videotranscriber
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videotranscriber', get_string('pluginname', 'local_videotranscriber'));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_videotranscriber/openaiapikey',
        get_string('openaiapikey', 'local_videotranscriber'),
        get_string('openaiapikey_desc', 'local_videotranscriber'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);
}
