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
 * Install steps for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_block_scormreport_install() {
    global $DB;

    // Create the course custom field if it does not exist
    $hoursfield = get_config('block_scormreport', 'hours_field');
    if (!$hoursfield) {
        // Reuse a course custom field that sites created for earlier releases ('horas'),
        // otherwise create the default 'hours' field.
        $hoursfield = 'hours';
        $legacy = $DB->record_exists_sql(
            "SELECT 1
               FROM {customfield_field} cf
               JOIN {customfield_category} cc ON cc.id = cf.categoryid
              WHERE cf.shortname = :shortname
                AND cc.component = :component
                AND cc.area = :area",
            ['shortname' => 'horas', 'component' => 'core_course', 'area' => 'course']
        );
        if ($legacy) {
            $hoursfield = 'horas';
        }
        set_config('hours_field', $hoursfield, 'block_scormreport');
    }

    // Check if it already exists in ANY course category
    $existingfield = $DB->get_record_sql(
        "SELECT cf.id, cf.categoryid
           FROM {customfield_field} cf
           JOIN {customfield_category} cc ON cc.id = cf.categoryid
          WHERE cf.shortname = :shortname
            AND cc.component = :component
            AND cc.area = :area",
        ['shortname' => $hoursfield, 'component' => 'core_course', 'area' => 'course']
    );

    if (!$existingfield) {
        // Use the Moodle custom fields handler
        $handler = \core_course\customfield\course_handler::create();
        $categoryname = get_string('setting_hours_field', 'block_scormreport');

        // Find or create category
        $category = $DB->get_record('customfield_category', [
            'name'      => $categoryname,
            'component' => 'core_course',
            'area'      => 'course',
        ]);

        if (!$category) {
            $catctrl = \core_customfield\category_controller::create(0, (object)[
                'name'      => $categoryname,
                'component' => 'core_course',
                'area'      => 'course',
                'itemid'    => 0,
            ], $handler);
            $catctrl->save();
            $categoryid = $catctrl->get('id');
        } else {
            $categoryid = $category->id;
        }

        // Create field
        $fielddata = (object)[
            'shortname'    => $hoursfield,
            'name'         => get_string('hoursfieldname', 'block_scormreport'),
            'type'         => 'text',
            'categoryid'   => $categoryid,
            'configdata'   => json_encode([
                'required'     => '0',
                'uniquevalues' => '0',
                'defaultvalue' => '',
                'displaysize'  => 10,
                'maxlength'    => 20,
                'ispassword'   => '0',
                'link'         => '',
                'linktarget'   => '',
            ]),
        ];
        $catctrlforfield = \core_customfield\category_controller::create($categoryid);
        $fieldctrl = \core_customfield\field_controller::create(0, $fielddata, $catctrlforfield);
        $fieldctrl->save();

        // Flag that we created the field (for cleanup on uninstall).
        set_config('created_hours_field', 1, 'block_scormreport');
        set_config('created_hours_fieldid', $fieldctrl->get('id'), 'block_scormreport');
        set_config('created_hours_categoryid', $categoryid, 'block_scormreport');
    }

    // ── License ──
    // Since 1.10.0 there is no automatic trial on install. The administrator requests the
    // free trial by email (or enters a license key) from the plugin's license page.
    require_once(__DIR__ . '/../classes/license.php');
    \block_scormreport\license::store_dates(0, 0, '');

    // NOTE: The block is NOT automatically added to existing courses on install.
    // To add it to all existing courses, use the button in
    // Site administration > Plugins > Blocks > SCORM Report settings,
    // or the block will be added to new courses if auto_add_new_courses is enabled.

    return true;
}
