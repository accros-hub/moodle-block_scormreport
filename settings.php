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
 * Settings for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // ── License status & activation ──
    $expiry = \block_scormreport\license::effective_expiry();
    $valid  = \block_scormreport\license::is_valid();
    $ltype  = \block_scormreport\license::get_type();

    if ($valid && $expiry) {
        if ($ltype === 'trial') {
            $statushtml = '<div style="padding:10px 14px;border-radius:8px;background:#fff3cd;color:#856404;font-weight:600;">⏳ '
                . get_string('license_trial_until', 'block_scormreport', userdate($expiry, '%d/%m/%Y')) . '</div>';
        } else {
            $statushtml = '<div style="padding:10px 14px;border-radius:8px;background:#d4edda;color:#155724;font-weight:600;">✅ '
                . get_string('license_active_until', 'block_scormreport', userdate($expiry, '%d/%m/%Y')) . '</div>';
        }
    } else {
        $statushtml = '<div style="padding:10px 14px;border-radius:8px;background:#f8d7da;color:#721c24;font-weight:600;">🔒 '
            . \block_scormreport\license::inactive_status_text() . '</div>';
    }

    // Trial request, key activation and release live on their own page (the settings page is already a form).
    $licensepage = new moodle_url('/blocks/scormreport/license_page.php');
    $statushtml .= '<div style="margin-top:12px;"><a class="btn btn-primary" href="' . $licensepage->out(true) . '">'
        . get_string('license_manage', 'block_scormreport') . '</a></div>';

    $settings->add(new admin_setting_heading(
        'block_scormreport/license_heading',
        get_string('license_heading', 'block_scormreport'),
        $statushtml
    ));

    // Pass threshold (percentage of SCOs passed). Range 0-100 enforced at read time.
    $settings->add(new admin_setting_configtext(
        'block_scormreport/apt_threshold',
        get_string('setting_apt_threshold', 'block_scormreport'),
        get_string('setting_apt_threshold_desc', 'block_scormreport'),
        '75',
        PARAM_INT
    ));

    // Course hours custom field shortname.
    $settings->add(new admin_setting_configtext(
        'block_scormreport/hours_field',
        get_string('setting_hours_field', 'block_scormreport'),
        get_string('setting_hours_field_desc', 'block_scormreport'),
        'hours',
        PARAM_ALPHANUMEXT
    ));

    // Default reminder channel.
    $settings->add(new admin_setting_configselect(
        'block_scormreport/default_channel',
        get_string('setting_default_channel', 'block_scormreport'),
        get_string('setting_default_channel_desc', 'block_scormreport'),
        'internal',
        [
            'email'    => get_string('channelemail', 'block_scormreport'),
            'internal' => get_string('channelinternal', 'block_scormreport'),
            'both'     => get_string('channelboth', 'block_scormreport'),
        ]
    ));

    // Deadline tolerance (points). Range 0-100 enforced at read time.
    $settings->add(new admin_setting_configtext(
        'block_scormreport/deadline_tolerance',
        get_string('setting_deadline_tolerance', 'block_scormreport'),
        get_string('setting_deadline_tolerance_desc', 'block_scormreport'),
        '15',
        PARAM_INT
    ));

    // ── Automatic reminders ──
    $settings->add(new admin_setting_heading(
        'block_scormreport/autoreminder_heading',
        get_string('setting_autoreminder_heading', 'block_scormreport'),
        get_string('setting_autoreminder_heading_desc', 'block_scormreport')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_scormreport/autoreminder_enabled',
        get_string('setting_autoreminder_enabled', 'block_scormreport'),
        get_string('setting_autoreminder_enabled_desc', 'block_scormreport'),
        '0'
    ));

    // Days of inactivity. Range 1-365 enforced at read time.
    $settings->add(new admin_setting_configtext(
        'block_scormreport/autoreminder_days',
        get_string('setting_autoreminder_days', 'block_scormreport'),
        get_string('setting_autoreminder_days_desc', 'block_scormreport'),
        '7',
        PARAM_INT
    ));

    // Reminder threshold. Range 0-100 enforced at read time.
    $settings->add(new admin_setting_configtext(
        'block_scormreport/autoreminder_threshold',
        get_string('setting_autoreminder_threshold', 'block_scormreport'),
        get_string('setting_autoreminder_threshold_desc', 'block_scormreport'),
        '75',
        PARAM_INT
    ));

    // ── Block installation ──
    $settings->add(new admin_setting_heading(
        'block_scormreport/install_heading',
        get_string('setting_install_heading', 'block_scormreport'),
        get_string('setting_install_heading_desc', 'block_scormreport')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_scormreport/auto_add_new_courses',
        get_string('setting_auto_add_new', 'block_scormreport'),
        get_string('setting_auto_add_new_desc', 'block_scormreport'),
        '0'
    ));

    // Button to add the block to all existing courses (opt-in).
    $addurl = new moodle_url('/blocks/scormreport/addtoall.php', ['sesskey' => sesskey()]);
    $settings->add(new admin_setting_description(
        'block_scormreport/add_to_all_desc',
        get_string('setting_add_to_all', 'block_scormreport'),
        get_string('setting_add_to_all_desc', 'block_scormreport')
        . '<br><a class="btn btn-secondary mt-2" href="' . $addurl->out(false) . '">'
        . get_string('setting_add_to_all_button', 'block_scormreport') . '</a>'
    ));

    // ── Training Compliance dashboard role access ──
    $settings->add(new admin_setting_heading(
        'block_scormreport/dashboard_heading',
        get_string('setting_dashboard_heading', 'block_scormreport'),
        get_string('setting_dashboard_heading_desc', 'block_scormreport')
    ));

    // Build the list of roles for the multiselects.
    $allroles = role_get_names(\context_system::instance());
    $roleoptions = [];
    foreach ($allroles as $r) {
        $roleoptions[$r->id] = $r->localname . ' (' . $r->shortname . ')';
    }

    // Roles that see ALL courses in the dashboard.
    $defaultall = [];
    foreach ($allroles as $r) {
        if ($r->shortname === 'manager') {
            $defaultall[] = $r->id;
        }
    }
    $settings->add(new admin_setting_configmultiselect(
        'block_scormreport/dashboard_roles_all',
        get_string('setting_dashboard_roles_all', 'block_scormreport'),
        get_string('setting_dashboard_roles_all_desc', 'block_scormreport'),
        $defaultall,
        $roleoptions
    ));

    // Roles that see ONLY their own courses (enrolled with that role).
    $defaultown = [];
    foreach ($allroles as $r) {
        if (in_array($r->shortname, ['supervisor', 'profesor', 'gcurso'])) {
            $defaultown[] = $r->id;
        }
    }
    $settings->add(new admin_setting_configmultiselect(
        'block_scormreport/dashboard_roles_own',
        get_string('setting_dashboard_roles_own', 'block_scormreport'),
        get_string('setting_dashboard_roles_own_desc', 'block_scormreport'),
        $defaultown,
        $roleoptions
    ));

    // ── Tutor activity ──
    $settings->add(new admin_setting_heading(
        'block_scormreport/tutor_heading',
        get_string('setting_tutor_heading', 'block_scormreport'),
        ''
    ));
    // Default: teacher roles with or without editing rights, plus common custom tutor roles.
    $teacherarchetypes = ['editingteacher', 'teacher'];
    $defaulttutor = [];
    foreach ($allroles as $r) {
        if (in_array($r->archetype, $teacherarchetypes) || in_array($r->shortname, ['profesor', 'tutor'])) {
            $defaulttutor[] = $r->id;
        }
    }
    $settings->add(new admin_setting_configmultiselect(
        'block_scormreport/tutor_roles',
        get_string('setting_tutor_roles', 'block_scormreport'),
        get_string('setting_tutor_roles_desc', 'block_scormreport'),
        $defaulttutor,
        $roleoptions
    ));
}
