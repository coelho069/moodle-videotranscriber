<?php
/**
 * Video Transcriber - Upgrade script
 *
 * @package local_videotranscriber
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_videotranscriber_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024010101) {
        // Cria a tabela local_videotranscriber se não existir
        $table = new xmldb_table('local_videotranscriber');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('cmid',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('status',        XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'processing');
            $table->add_field('transcription', XMLDB_TYPE_TEXT,    null,  null, null, null, null);
            $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cmid', XMLDB_INDEX_UNIQUE, ['cmid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024010101, 'local', 'videotranscriber');
    }

    return true;
}
