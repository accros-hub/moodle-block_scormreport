# Course Report — SCORM reporting for Moodle

**Component:** `block_scormreport`
**Release:** `1.12.8-v174`
**Supported Moodle versions:** 5.0, 5.1, 5.2 and 5.3
**License:** GNU GPL v3 or later
**Maintainer:** Accros Business Solutions

Course Report is a Moodle block that helps tutors, training coordinators and authorised administrators monitor learner progress in SCORM activities. It brings SCORM tracking information together in course-level reports, with exports, learner reminders, tutor activity and a cross-course Training Compliance view.

## Who is it for?

Training providers, organisations that deliver online training with Moodle, course tutors, training coordinators and Moodle administrators who need a practical way to review SCORM activity and follow learner progress.

## Main features

- **Course report:** every learner with SCORM time, score, % of topics viewed and passed, access dates and threshold status.
- **Learner and activity drill-down:** expandable detail per learner and per SCORM, with a second level for multi-SCO packages.
- **Test answers:** for each question, the question number, the answer the learner marked and whether it was correct.
- **Configurable columns and live filters:** each user chooses the optional columns shown on screen and in the exports, and filters by company, % passed, minimum score and threshold status.
- **Excel export:** summary sheet and activity matrix.
- **PDF reports:** course report, individual learner compliance evidence and daily connection report (dates, first connection, activity and IP addresses).
- **Tutor activity:** a view that lists only the tutors of the course (teachers with or without editing rights) with their role, first and last access, days with activity and connections, the activity of each day (connections, first and last connection) and a **Print connections** PDF.
- **Learner reminders:** manual and scheduled reminders by email and/or Moodle messaging, with a reminder log.
- **Deadline status:** on track / tight / at risk.
- **Training Compliance dashboard:** cross-course view for authorised users.
- **Learner view:** each learner can review their own SCORM progress.
- **Access control** with Moodle roles and capabilities.

Feature availability depends on Moodle capabilities and on the plugin license.

The accuracy of report values depends on the data each SCORM package sends to Moodle and on the site configuration.

## Requirements

- **Moodle:** 5.0, 5.1, 5.2 or 5.3.
- **PHP:** the version required by your Moodle release (PHP 8.2 or later for Moodle 5.0 and 5.1; PHP 8.3 or later for Moodle 5.2 and 5.3).
- PHP **OpenSSL** extension, to verify the signed license token.
- PHP **cURL** extension and outbound HTTPS access to `https://clientes.accros.es`, to activate and refresh the license.
- **Moodle cron** running, for scheduled reminders and license refresh.
- No other Moodle plugin is required. If **mod_diploma** is installed and the course has a diploma activity, the report adds a Diploma column.

## Installation

1. Back up the Moodle site and database.
2. Download the plugin ZIP from Moodle Marketplace.
3. In Moodle, open **Site administration → Plugins → Install plugins**.
4. Upload the ZIP and follow Moodle's validation steps.
5. Open **Site administration → Notifications** to complete the installation.
6. Review the plugin settings in **Site administration → Plugins → Blocks → Course Report**.
7. Open **Manage license** and request the free trial or activate your license key.
8. Add the Course Report block to your courses, or use **Add to all existing courses** in the settings.
9. Check that Moodle cron is running and test the report with a test course before using it in production.

## Configuration

| Setting | What it does | Default |
|---|---|---|
| Pass threshold (%) | Percentage of SCOs passed that marks a learner as above the threshold | 75 |
| Course hours field | Short name of the course custom field with the course hours | `hours` (created on install; an existing `horas` field is reused) |
| Default reminder channel | Email, Moodle message or both | Moodle message |
| Deadline tolerance (points) | Margin used by the deadline status | 15 |
| Automatic reminders | Enable them, days of inactivity and progress threshold | Off; 7 days; 75 % |
| Automatically add to new courses | Adds the block to new courses | Off |
| Add to existing courses | Adds the block to every existing course | Button |
| Training Compliance dashboard access | Roles that see all courses and roles that see only their own courses | — |
| Tutor roles | Roles listed in the Tutor activity view | Teacher roles with or without editing rights |

The available settings and labels are shown in the installed Moodle site.

## Roles and capabilities

| Capability | Allows | Default roles |
|---|---|---|
| `block/scormreport:addinstance` | Add the block to a course | Editing teacher, Manager |
| `block/scormreport:myaddinstance` | Add the block to the dashboard | Manager |
| `block/scormreport:viewreports` | View the course report and the Tutor activity view | Teacher, Editing teacher, Manager |
| `block/scormreport:viewownreport` | View own progress | Student |
| `block/scormreport:export` | Export Excel and PDF reports | Editing teacher, Manager |
| `block/scormreport:sendreminders` | Send reminders | Editing teacher, Manager |
| `block/scormreport:viewcompliance` | View the Training Compliance dashboard | Manager |

Custom roles, such as supervisor or inspector roles, need these capabilities in **Site administration → Users → Permissions → Define roles**. Review the role permissions of your site before giving access to personal learner data.

## Understanding the report figures

- **% topics passed** counts individual SCOs. A multi-SCO package with 11 SCOs counts as 11 items.
- **Score** is the mean of the SCO scores. It may differ from the package's own weighted grade.
- **Threshold status** compares the % of SCOs passed with the configured threshold. It is not Moodle course completion.
- **SCORM time** is the time reported by the SCORM package (`cmi.core.total_time` / `cmi.total_time`), taking the highest value per SCO across attempts.
- **Test answers** are shown as the SCORM package stores them. Authoring tools often store choice answers as identifiers, replacing spaces, punctuation and accented letters with `_`. The report turns `_` back into spaces and `__` into commas, but letters that the package itself replaced cannot be recovered. ActivePresenter packages report only the internal id of the chosen answer (for example `69_6`); for them the report reads the answer text from the package itself.
- **Tutor activity** counts every logged action of the tutor in the course (viewing, creating, updating and deleting content). A new connection starts with each Moodle login, after 30 minutes without activity in the course, or on a new day.

Validate representative SCORM packages before using the reports as formal evidence.

## License and activation

Course Report is a commercial plugin. The code is GPL v3 or later; a license key enables the report on one Moodle site. The license is a signed token verified locally with an embedded public key. The private signing key is not included in the plugin.

- **Free trial (30 days):** download the plugin with the free *Trial* option in Moodle Marketplace. On the license page, enter an email address and a 30-day trial key with every feature is sent to it. The trial works only on the Moodle site that requested it, and can be used once per site and email. The email address is used only to send and manage the trial license.
- **Buying a license:** buy the plugin in Moodle Marketplace. The license key is sent to the email address used for the purchase. Paste it on the license page and press **Activate**.
- **Renewal:** renew on the plugin's page in Moodle Marketplace. The new period reaches the site on the next automatic check, or at once with **Check now** on the license page.
- **Moving to a new server:** on the old site, use **Remove license from this Moodle**, then activate the key on the new site.

### What happens when the license ends

- **Valid license:** the report works in every course.
- **License ended** (trial not converted or license not renewed): courses that started on or before the license end date keep their full report, so running and finished courses are not affected. Courses that start later show a locked page until the license is renewed. Courses without a start date use their creation date.
- **License suspended or cancelled:** the report is locked in every course.

No Moodle data or reminder log is deleted in any of these cases. The end date always comes from the signed license token; editing dates in the database has no effect.

## Data protection and external communication

The plugin reads Moodle course, user, log and SCORM data to build the reports. It stores the reminders sent in the table `block_scormreport_notif` and implements the Moodle Privacy API for that data.

The daily connection report and the Tutor activity view show personal data such as access dates and IP addresses. Give access to them only to authorised staff and handle them according to your data protection rules.

The plugin connects to the Accros licensing server (`https://clientes.accros.es/api/v1/`) only to activate, check, renew or remove the license and to request a trial. It sends:

- the address of the Moodle site (`wwwroot`);
- the Moodle release and the plugin version;
- the license key (when activating) or the signed license token (when checking or removing);
- the email address entered by the administrator (only when requesting a trial).

No data about learners, tutors, courses, grades or SCORM activity is sent to the licensing server.

## Scheduled tasks

- **Send automatic Course Report reminders:** daily at 07:00 server time, when automatic reminders are enabled.
- **Refresh Course Report license:** every hour; the licensing server is contacted only when the token says a check is due. If the server cannot be reached, the current license keeps working.

## Troubleshooting

1. **The block or the report is not visible:** check that the installation finished in Site administration → Notifications and that the user has the right capability.
2. **A user cannot open the report:** check enrolment, role assignments, capability overrides and whether the license covers that course.
3. **The Tutor activity view is empty:** check the **Tutor roles** setting and that the tutors have one of those roles in the course.
4. **The Diploma column is not offered:** the course needs a diploma activity (mod_diploma).
5. **License activation fails:** check the key, outbound HTTPS access, cURL/OpenSSL and the licensing server status.
6. **Automatic reminders are not sent:** check Moodle cron, the task logs, the email/messaging configuration and the reminder settings.
7. **Report values differ from the SCORM package:** check the package's SCO structure, SCORM version, attempts and the values it reports.
8. **Exports fail:** check the Moodle and PHP error logs and the permissions of the user exporting.

## Languages

The Moodle Marketplace package includes English; other languages are available through AMOS. The full package includes English, Spanish, French and German.

## Documentation

- [Installation and upgrade](docs/INSTALLATION.md)
- [Administrator guide](docs/ADMIN_GUIDE.md)
- [Tutor and learner guide](docs/USER_GUIDE.md)
- [Privacy and data processing](docs/PRIVACY.md)

## Support

- Documentation: https://www.accros.es/course-report/docs
- Issue tracker: https://www.accros.es/course-report/issues
- Support contact: https://www.accros.es/course-report/support
- Website: https://www.accros.es

## Copyright and license

© 2026 Accros Business Solutions.

GNU General Public License v3 or later. See the `LICENSE` file included with the plugin.
