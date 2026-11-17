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
 * License management page for block_scormreport: free trial request, key activation,
 * manual check and release of this site.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');

use block_scormreport\license;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$pageurl = new moodle_url('/blocks/scormreport/license_page.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('license_page_title', 'block_scormreport'));
$PAGE->set_heading(get_string('pluginname', 'block_scormreport'));
license::reset_caches();

$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '' && data_submitted()) {
    require_sesskey();
    $type = \core\output\notification::NOTIFY_ERROR;
    $message = '';

    switch ($action) {
        case 'trial':
            $email = trim(optional_param('email', '', PARAM_RAW_TRIMMED));
            if (!validate_email($email)) {
                $message = get_string('result_invalid_email', 'block_scormreport');
                break;
            }
            $r = license::request_trial($email);
            if ($r['ok']) {
                $type = \core\output\notification::NOTIFY_SUCCESS;
                $message = get_string('result_trial_sent', 'block_scormreport', s($email));
            } else {
                $message = block_scormreport_license_message($r['code']);
            }
            break;

        case 'activate':
            $code = trim(optional_param('code', '', PARAM_RAW_TRIMMED));
            $r = license::activate_key($code);
            if ($r['ok']) {
                $type = \core\output\notification::NOTIFY_SUCCESS;
                $message = get_string('activate_success', 'block_scormreport', userdate($r['expires'], get_string('strftimedate', 'langconfig')));
            } else {
                $message = block_scormreport_license_message($r['code']);
            }
            break;

        case 'check':
            $r = license::refresh();
            if ($r['ok']) {
                $type = \core\output\notification::NOTIFY_SUCCESS;
            }
            $message = block_scormreport_license_message($r['code']);
            break;

        case 'release':
            $r = license::deactivate();
            if ($r['ok']) {
                $type = \core\output\notification::NOTIFY_SUCCESS;
            }
            $message = block_scormreport_license_message($r['code']);
            break;
    }
    redirect($pageurl, $message, null, $type);
}

/**
 * Localised message for a licensing server code.
 *
 * @param string $code Code.
 * @return string
 */
function block_scormreport_license_message(string $code): string {
    if ($code === 'trial_sent') {
        return get_string('result_trial_sent_short', 'block_scormreport');
    }
    $sm = get_string_manager();
    if (preg_match('/^[a-z_]+$/', $code) && $sm->string_exists('result_' . $code, 'block_scormreport')) {
        return get_string('result_' . $code, 'block_scormreport');
    }
    return get_string('result_server_error', 'block_scormreport');
}

// ---------- Page ----------
$valid = license::is_valid();
$expiry = license::effective_expiry();
$ltype = license::get_type();
$hastoken = license::has_token();
$last = license::last_check();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('license_page_title', 'block_scormreport'));

// Status.
if ($valid) {
    $status = $ltype === 'trial'
        ? get_string('license_trial_until', 'block_scormreport', userdate($expiry, get_string('strftimedate', 'langconfig')))
        : get_string('license_active_until', 'block_scormreport', userdate($expiry, get_string('strftimedate', 'langconfig')));
    echo $OUTPUT->notification($status, $ltype === 'trial' ? 'warning' : 'success', false);
} else {
    $status = license::inactive_status_text();
    echo $OUTPUT->notification($status, 'error', false);
}

$details = [];
if ($hastoken) {
    $details[] = get_string('license_ref', 'block_scormreport', s(license::license_reference()));
    if (license::key_last4() !== '') {
        $details[] = get_string('license_key_ending', 'block_scormreport', s(license::key_last4()));
    }
}
$details[] = get_string('license_site', 'block_scormreport', s(license::current_site()));
if ($last['time']) {
    $details[] = get_string('license_lastcheck', 'block_scormreport', (object)[
        'time' => userdate($last['time'], get_string('strftimedatetimeshort', 'langconfig')),
        'result' => block_scormreport_license_message($last['code']),
    ]);
}
echo html_writer::alist($details, ['class' => 'list-unstyled text-muted mb-4']);
echo html_writer::tag('p', get_string('license_scope_desc', 'block_scormreport'), ['class' => 'mb-4', 'style' => 'max-width:720px']);

$hidden = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$card = function (string $title, string $body) {
    return html_writer::div(
        html_writer::div(html_writer::tag('h4', $title, ['class' => 'card-title h5']) . $body, 'card-body'),
        'card mb-3', ['style' => 'max-width:720px']
    );
};

// Free trial (only when the site has never had a license from the server and is not licensed).
if (!$hastoken && !$valid && license::get_type() === '') {
    global $USER;
    $form = html_writer::tag('p', get_string('license_trial_desc', 'block_scormreport'))
        . html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'form-inline']) . $hidden
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'trial'])
        . html_writer::label(get_string('license_trial_email', 'block_scormreport'), 'bsr_email', true, ['class' => 'mr-2 me-2'])
        . html_writer::empty_tag('input', ['type' => 'email', 'name' => 'email', 'id' => 'bsr_email', 'required' => 'required',
            'value' => $USER->email, 'class' => 'form-control mr-2 me-2', 'style' => 'min-width:280px'])
        . html_writer::tag('button', get_string('license_trial_button', 'block_scormreport'), ['type' => 'submit', 'class' => 'btn btn-primary'])
        . html_writer::end_tag('form')
        . html_writer::tag('p', get_string('license_trial_privacy', 'block_scormreport'), ['class' => 'small text-muted mt-2 mb-0']);
    echo $card(get_string('license_trial_heading', 'block_scormreport'), $form);
}

// Activate with a key.
$form = html_writer::tag('p', get_string('license_key_desc', 'block_scormreport'))
    . html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'form-inline']) . $hidden
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'activate'])
    . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'required' => 'required', 'autocomplete' => 'off',
        'placeholder' => 'ACC-XXXX-XXXX-XXXX-XXXX', 'class' => 'form-control mr-2 me-2',
        'style' => 'min-width:320px;font-family:monospace;text-transform:uppercase'])
    . html_writer::tag('button', get_string('license_activate_button', 'block_scormreport'), ['type' => 'submit', 'class' => 'btn btn-primary'])
    . html_writer::end_tag('form');
echo $card(get_string('license_key_heading', 'block_scormreport'), $form);

// Check now (also after the license ended, to pick up a renewal) / release.
if ($hastoken || license::refresh_possible()) {
    $check = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline-block mr-2 me-2']) . $hidden
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'check'])
        . html_writer::tag('button', get_string('license_check_button', 'block_scormreport'), ['type' => 'submit', 'class' => 'btn btn-secondary'])
        . html_writer::end_tag('form');
    $release = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline-block',
            'onsubmit' => 'return confirm(' . json_encode(get_string('license_release_confirm', 'block_scormreport')) . ');']) . $hidden
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'release'])
        . html_writer::tag('button', get_string('license_release_button', 'block_scormreport'), ['type' => 'submit', 'class' => 'btn btn-outline-danger'])
        . html_writer::end_tag('form');
    echo $card(get_string('license_manage', 'block_scormreport'),
        html_writer::tag('p', get_string('license_release_desc', 'block_scormreport')) . $check . ($hastoken ? $release : ''));
}

echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'blocksettingscormreport']),
    get_string('license_back_settings', 'block_scormreport'), ['class' => 'btn btn-link px-0']);

echo $OUTPUT->footer();
