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
 * Admin settings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videotranscriber', get_string('pluginname', 'local_videotranscriber'));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_videotranscriber/openai_api_key',
        get_string('settings_apikey', 'local_videotranscriber'),
        get_string('settings_apikey_desc', 'local_videotranscriber'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);
}
