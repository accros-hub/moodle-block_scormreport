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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Backup task for the SCORM Report block.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot .
    '/blocks/scormreport/backup/moodle2/backup_scormreport_block_stepslib.php');

/**
 * Register the backup steps for the SCORM Report block.
 */
class backup_scormreport_block_task extends backup_block_task {
    /**
     * Define block-specific backup settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Register the notification backup step.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_scormreport_block_structure_step(
            'scormreport_structure',
            'scormreport.xml'
        ));
    }

    /**
     * Return the areas whose files are included in the backup.
     *
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * Return configuration fields containing encoded content.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * Return content with backup links encoded.
     *
     * @param string $content Content to process.
     * @return string
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
