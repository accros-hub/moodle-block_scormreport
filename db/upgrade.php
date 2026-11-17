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
 * Upgrade steps for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_block_scormreport_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026091600) {
        $table = new xmldb_table('block_scormreport_notif');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('channel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'internal');
            $table->add_field('reason', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('progress', XMLDB_TYPE_NUMBER, '5,1', null, null, null, null);
            $table->add_field('threshold', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'sent');
            $table->add_field('senderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        } else {
            $field = new xmldb_field('progress', XMLDB_TYPE_NUMBER, '5,1', null, null, null, null, 'reason');
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_precision($table, $field);
            }
        }
        upgrade_block_savepoint(true, 2026091600, 'scormreport');
    }

    if ($oldversion < 2026102600) {
        // 1.11: the license comes only from tokens issued by the Accros licensing server.
        // Remove every value left by the license systems of earlier versions (nothing is migrated:
        // those licenses were never sold).

        // Do not delete core configuration keys here: they share the global
        // namespace and may belong to Moodle or other components.
        // Legacy global license-related values are left untouched.

        // Plugin values of the 1.9 local license and the ACCROS2 signed codes.
        $pluginkeys = [
            'license_install_day',
            'license_install_month',
            'license_install_year',
            'license_expiry_day',
            'license_expiry_month',
            'license_expiry_year',
            'license_integrity_hash',
            'license_type',
            'lic_code',
        ];
        foreach ($pluginkeys as $name) {
            unset_config($name, 'block_scormreport');
        }

        // The current server token also becomes the last token: it decides which courses keep
        // working after the license ends.
        $token = (string)get_config('block_scormreport', 'lic_token');
        if ($token !== '' && (string)get_config('block_scormreport', 'lic_lasttoken') === '') {
            set_config('lic_lasttoken', $token, 'block_scormreport');
        }
        if ($token === '') {
            // Stored dates are only a display cache of the token.
            set_config('lic_start', 0, 'block_scormreport');
            set_config('lic_expiry', 0, 'block_scormreport');
            set_config('lic_type', '', 'block_scormreport');
        }

        upgrade_block_savepoint(true, 2026102600, 'scormreport');
    }

    return true;
}

