## 1.12.8-v174 (2026-11-21)

- MMRT-293: Include notification history in course backups with user data and restore it with Moodle's user mappings.
- Restore each notification from its XML path without grouping the block root.

## 1.12.7-v173.1 (2026-11-16)

- Security hardening: legacy license upgrade no longer deletes global Moodle configuration keys.
- Retains cleanup of block_scormreport-scoped legacy license settings and token handling.

# Changelog

## 1.12.7-v173 — 2026-11-13

- Test answers of ActivePresenter packages: ActivePresenter reports the internal id of the chosen answer (for example `69_6`) instead of its text. The report and the compliance evidence PDF now read the answer text from the package (`rlprez.js`) and show it. Other packages are not affected.
- Bumped plugin version to `2026111300`.

## 1.12.6-v172 — 2026-11-12

Moodle Marketplace review preparation.

- Privacy API: the report columns chosen by each user (`block_scormreport_columns` user preference) are now declared and exported.
- Excel export uses the PhpSpreadsheet library loaded by Moodle (`excellib.class.php`) instead of loading `vendor/autoload.php` by path, which does not exist under `dirroot` from Moodle 5.1.
- PDF reports use Moodle's `pdf` class (`lib/pdflib.php`) instead of loading TCPDF directly.
- Course hours field: the default short name is now `hours`. New installations reuse an existing `horas` field; existing sites keep their setting.
- Uninstall removes the course hours field it created through the custom field API.
- English language strings sorted alphabetically; titles in sentence case.
- Documentation added in `docs/`; README updated with the 30-day trial.
- Bumped plugin version to `2026111201`.

## 1.12.5-v171 — 2026-11-12

- Tutor activity: activity split by day, with the number of connections, first and last connection of each day.
- Tutor connections are counted again: a new connection starts with each Moodle login, after 30 minutes without activity or on a new day. Two logins a few minutes apart now count as two connections.
- The connections button is now a red "PDF · Print connections" button, like "Print report".
- Bumped plugin version to `2026111200`.

## 1.12.4-v170 — 2026-11-11

- New README with the full documentation: features, requirements, installation, settings with their defaults, capabilities, report figures, licensing, data protection, scheduled tasks, troubleshooting, languages and support.
- Moodle 5.3 listed as supported in the documentation (tested).
- Bumped plugin version to `2026111104`.

## 1.12.3-v169 — 2026-11-11

- Test answers (report detail and compliance evidence PDF) now show three columns: question number, marked answer and result. The correct answer column was removed.
- Answers are made readable: "_" becomes a space, "__" a comma, and SCORM 2004 separators are shown as ", ". Letters that the SCORM package itself replaced (for example accented letters) cannot be recovered.
- Supported Moodle versions extended to 5.3 (tested).
- Bumped plugin version to `2026111103`.

## 1.12.2-v168 — 2026-11-11

- Columns selector: Diploma is only offered when the course has a diploma activity (mod_diploma), and the Evidence and Connections buttons only to users who can export. Hidden options keep their saved state for other courses.
- Bumped plugin version to `2026111102`.

## 1.12.1-v167 — 2026-11-11

- New "Tutor activity" button in the course report (global view), for everyone who can view the report; not shown in the student view. It opens a view that lists only the tutors of the course (teachers with or without editing rights) with their role, email, first and last access, days with activity, number of sessions and the daily connections PDF.
- New setting "Tutor roles" to choose which roles count as tutors (default: roles based on the editingteacher and teacher archetypes).
- Tutor activity counts every logged action (create, read, update, delete); student figures are unchanged.
- The daily connections PDF also works for tutors who have the role in the course without being enrolled.
- Bumped plugin version to `2026111101` (above the discarded v166 build).

## 1.12.0-v165 — 2026-11-10 (Moodle Marketplace preparation)

- Two packages: Marketplace (English only; other languages through AMOS) and full (EN, ES, FR, DE).
- Product name unified as "Course Report" in every language, including the Excel sheet, PDF titles and admin paths.
- License texts: removed the fixed trial length (it is set on the licensing server) and every message that sent users to buy outside Moodle Marketplace. Renewal points to the plugin's page in Moodle Marketplace; the license page explains that keys bought there arrive at the purchase email.
- French and German: added the 49 license strings that were missing.
- Removed 12 obsolete strings (ACCROS2 codes and the old demo mode) and 3 duplicated keys.
- Removed the dead demo-mode code from report.php, including its inline script.
- Columns selector: its inline script moved to the `block_scormreport/report` AMD module. No inline scripts are left.
- AMD modules built with Moodle's Grunt toolchain (with source maps); ESLint errors fixed.
- styles.css is no longer requested a second time on each page (Moodle already includes it in the theme CSS).
- The "Hours" course custom field created on install now takes its name from the language strings.
- Code comments translated to English. Copyright unified as "2026 Accros Business Solutions" with full headers in every PHP and JS file.
- Tests: new `license_course_rule_test` (course start date rule, suspended/cancelled licenses, early removal, wrong site/product, test mode) using signed fixtures; `block_scormreport_lib_test` renamed to `lib_report_test` with namespace and PHPUnit attributes.
- README: licensing, what happens when the license ends, data sent to the licensing server, permissions and Support.
- Bumped plugin version to `2026111000`.

## 1.11.2 – 1.11.6 (v161–v164) — 2026-10/11

- Columns selector for the report, Excel and PDF; Company column (Moodle institution field).
- Daily connection report (PDF): date, first connection, activity and IP addresses.
- Compliance evidence PDF: removed the estimated activity time.
- SCORM time takes the highest value per SCO across attempts instead of adding them up; fixed a variable clash that hid SCORM activities in the per-student view.

## 1.11.1-v159 — 2026-10-26

- Locked page: administrators get a button to the license page ("Activate license" when the site was never licensed, "Manage license" otherwise). Other users only see "Back to course".
- Bumped plugin version to `2026102601`.

## 1.11.0-v158 — 2026-10-26 (license rule by course start date)

- New license rule per course (`license::course_allowed($course)`), used by the report, exports, student view, reminders, both PDFs, the compliance dashboard and the automatic reminders task:
  - Valid license: every course works.
  - License ended (trial not converted, license not renewed): courses that started on or before the license end date keep their full report; courses that start later (new courses, copies) show the locked page. Courses without a start date use their creation date.
  - License suspended or cancelled by Accros: every course is locked.
- The end date always comes from signed data: the last valid token is kept (`lic_lasttoken`) and never deleted when the license ends or is removed from this Moodle. Editing dates in the database has no effect.
- Automatic reminders are no longer sent for courses not covered by the license.
- Compliance dashboard: courses not covered are left out, with a notice showing how many.
- Block: students do not see the block in courses not covered by the license.
- Locked page explains the reason: course started after the license ended (with the date), license suspended, license cancelled, or not activated.
- Licensing server: the `refresh_license` task now runs every hour and contacts the server only when the token says a refresh is due. After the license ends it keeps asking once a day with the last token, so a renewal made in the Accros panel reaches this Moodle by itself; it stops when the server answers released, revoked, invalid_token, not_found or wrong_product.
- License page: "Check now" is also shown after the license has ended. "Remove the license from this Moodle" keeps the last token, so courses already started keep working here.
- Test mode for PHPUnit/Behat (`lic_testmode`), honoured only while tests are running.
- Removed the legacy license systems: ACCROS2 signed codes (`activate.php`, second public key), the 1.9 local trial and its marker in the core configuration. The upgrade step removes all their stored values, including the old core-configuration entries; the current server token is kept and also stored as the last token.
- Bumped plugin version to `2026102600`.

## 1.10.2-v157 — 2026-10-25

- Status text: when the stored dates claim a future expiry but there is no signed license behind them (data left by old versions or a copied configuration), the plugin now says the stored license is not valid and asks to activate the key again, instead of "License expired on <future date>".
- Bumped plugin version to `2026102501`.

## 1.10.1-v156 — 2026-10-25

- Settings and license page: when the license is not valid, the status now matches the locked report page ("License expired on …", "Trial period ended on …" or "License not activated"). It no longer shows the outdated "demo mode" text.
- Bumped plugin version to `2026102500`.

## 1.10.0-v155 — 2026-10-24 (central licensing)

- Licenses are now managed by the Accros licensing server (clientes.accros.es). New page **Manage license** (Site administration → Plugins → Blocks → SCORM Report):
  - Free 30-day trial requested by email. The key is sent to that address and only works on this Moodle site.
  - Activation with a license key `ACC-XXXX-XXXX-XXXX-XXXX`. The server returns a signed `ACCROS3` token bound to this site, verified offline with RSA on every check.
  - "Check now" and "Remove license from this Moodle" (to move the license to a new server).
- New scheduled task `refresh_license`: refreshes the token weekly (renewals, suspensions, revocations). If the server is unreachable the license keeps working until its end date.
- New installs no longer get an automatic 30-day trial; a "License not activated" message explains how to start the trial.
- Backwards compatible: `ACCROS2` codes and trials already running on upgraded sites keep working until they end. Legacy local trials are capped at 31 days from their start.
- Privacy API: declares the data sent to the licensing server (site address, versions and, for trials, the administrator's email).
- Bumped plugin version to `2026102400`.

## 1.9.12-v147 — 2026-10-09

- Student view: the donut chart next to the "% passed" figure is now drawn (passed / incomplete / not attempted). It was declared in the page but never rendered.
- Student view: the detail table now uses the full page width, like the summary cards and progress bars.
- Bumped plugin version to `2026100901`.

## 1.9.11-v146 — 2026-10-09

- Relabelled the "First activity / Last activity" columns to "First access / Last access" (ES: "Primer acceso / Último acceso"; FR: "Premier accès / Dernier accès"; DE: "Erster Zugriff / Letzter Zugriff"). The values are unchanged: earliest and latest SCORM tracking timestamp across all attempts.
- Bumped plugin version to `2026100900`.

## 1.9.10-v144 — 2026-10-08 (publication preparation)

- Included the complete GNU GPL version 3 license text in `LICENSE`.
- Added release documentation and publication verification checklist.
- No application runtime behavior changed relative to v143.
- Security, installation/upgrade, AMD build, and Moodle 5.0–5.2 compatibility still require environment-based verification before marketplace submission.

## 1.9.9-v143 — 2026-10-07

- Baseline supplied for publication audit. Detailed changes from v137 to v143 have not been independently reconstructed; consult development history before publishing release notes.


## 1.9.3-v136 — 2026-10-04

- Moved license state from global core-looking configuration keys to the plugin-owned `block_scormreport` configuration namespace.
- Added safe migration of valid v135 license data during upgrade.
- Added complete Moodle GPL/version metadata to `version.php`.
- Bumped plugin version to `2026100400`.
- Verified PHP syntax across the complete plugin tree.

## [1.5.2] - 2026-09-16

### Changed
- SCORM "completed" status now counts as passed for the % passed, threshold status, deadline and dashboard. The status badge still shows "Completed" (blue) to preserve the real value.

## [1.5.1] - 2026-09-16

### Fixed
- SCORM 2004 answers: interactions now read learner_response (2004) with student_response fallback (1.2). Fixes empty answers on ActivePresenter SCORM 2004 tests.
- Reminder preview: removed inline oninput="updatePreview()"; subject and body listeners now bound in the reminder AMD module.
- Merged duplicate HTML class attributes on toggle buttons (report.php, student.php).

### Changed
- "Question" column relabelled "Question ID" (it shows the interaction id, not the question text).
- Evaluation detail is labelled "(last attempt)" in report and evidence PDF.
- Full boilerplate GPL header added to every PHP file.
- Remaining visible "Topic/Tema/Thème/Thema" strings replaced by "SCORM".
- Remaining Spanish source comments translated to English.
- "Descargar PDF" column removed; "Evidence" button renamed "Per-student report".
- Added PHPUnit tests for interaction element parsing.

## [1.5.0] - 2026-09-15

### Added
- Test evaluation detail: reads SCORM cmi.interactions (questions, student answers, correct answers, result).
- "View answers" button in the tutor report expands a table per test SCORM: question ID, student answer, correct answer, result (correct/incorrect).
- New "Evaluation detail" section in the Compliance evidence PDF listing each test's questions and answers per student.
- scormreport_get_interactions() reads interactions in bulk using chunked queries.

## [1.4.4] - 2026-09-14 (release hardening)

### Changed
- All visible "topics/temas/thèmes/Themen" strings replaced by "SCOs" across the 4 languages (internal string keys kept for compatibility).
- Block name "Topic Report / Informe de Temas" renamed to "Course Report / Informe del curso".
- Reminder send button logic moved from inline onclick to the reminder AMD module (0 inline onclick remaining).
- compliance.php header comment updated to "Monitoring Dashboard".
- All source code comments translated to English.
- Removed orphan err_range_* strings.

## [1.4.3] - 2026-09-14 (release hardening)

### Fixed
- threshold=0 now works: replaced `?: default` (0 is falsy) with explicit `=== false` checks in report.php, generate_report_pdf.php, generate_evidence_pdf.php, compliance.php, send_auto_reminders.php.
- All duplicate HTML class attributes merged (report.php, student.php).
- UI terminology "topics" replaced by "SCOs" in visible strings across ES/EN/FR/DE (internal keys kept for compatibility).
- French/German "apt/notapt" labels replaced ("Seuil atteint/non atteint", "Schwelle erfüllt/nicht erfüllt").
- README no longer says "compliance dashboard" (now "monitoring dashboard").
- "Compliance alerts" renamed to "Monitoring alerts"; compliancelink relabelled across languages.
- CSS fully namespaced under .block_scormreport.
- install.xml VERSION bumped to 20260914.

## [1.4.2] - 2026-09-14

### Fixed (P0)
- Fatal error: scormreport_parse_scorm_time() did not exist (was scormreport_time_to_seconds). Function renamed with legacy alias; all 14 references and tests now resolve.

### Fixed (P1)
- Added cmi.core.session_time to element queries and time fallback (SCORM 1.2).
- scoaccessmap now merges MIN/MAX across chunks (correct Multi-SCO dates on large datasets).
- Upgrade is now defensive: corrects progress field precision even if the table already exists.
- Upgrade no longer auto-adds the block to all existing courses (consistent with install).
- auto_add_new_courses now defaults to 0 (conservative install).
- README corrected to match opt-in install behaviour.
- "Apto/No apto" fully replaced by "Cumple umbral/No cumple" across ES/EN/FR/DE (including onlyapt, setting labels).
- "Compliance Dashboard" renamed to "Training Compliance Dashboard".
- Manual reminders now exclude suspended and inactive enrolments.
- Removed add_external_location_link('exports') from Privacy metadata (no external third party).
- Daily activity grouping uses Moodle userdate() instead of PHP date() (timezone-correct).
- Settings now validate ranges (0-100 for thresholds, 1-365 for days).

### Changed (quality)
- CSS selectors namespaced under .block_scormreport.
- Channel cards converted to accessible labels (no onclick div).
- Fixed duplicate class attribute in reminder card headers.
- hours_field setting description warns about potential field-name collision.

## [1.4.1] - 2026-09-13

### Fixed (Marketplace readiness)
- db/upgrade.php: corrected `progress` field precision to NUMBER(5,1).
- Privacy API: full request provider for block_scormreport_notif table (get_contexts_for_userid, export_user_data, delete_data_for_*, get_users_in_context, delete_data_for_users).
- Connection time relabelled "Estimated activity time" with methodology note (no longer presented as literal connection time).
- Removed invented 1-minute duration for single-event sessions.
- SCORM time now sums ALL attempts (historical time), not just the last attempt.
- Reminder cooldown now only considers status='sent' (failed sends can retry).
- Manual reminders log each channel (email/internal) independently with its own status.
- "% Superados" relabelled "% SCOs passed"; "Score" relabelled "Score (SCO avg)".
- README updated with Moodle/PHP version matrix and semantic notes.

## [1.4.0] - 2026-07-XX

### Added
- Compliance evidence PDF (generate_evidence_pdf.php): student data, course data, activity breakdown, activity log, tutor signature.
- Compliance dashboard (compliance.php): per-course stats, alerts, % passed.
- Connection activity estimation from Moodle logs.

## [1.3.0] - 2026-07-XX

### Added
- Deadline traffic-light column (on track / tight / at risk).
- Plugin settings page (apt threshold, hours field, default channel, deadline tolerance).
- Automatic reminders (scheduled task) with audit log table block_scormreport_notif.
- Anti-spam cooldown for automatic reminders.
- $plugin->supported = [500, 502].

### Fixed
- Multi-SCO counted as individual SCOs (not 1 package).
- completed vs passed separated (only 'passed' is passed).
- SCORM 2004 success_status priority over completion_status.
- Message provider 'progressreminder' (own, not moodle/instantmessage).
- Custom fields API instead of direct INSERT.
- Uninstall cleans own custom field and notification table.
- LIMIT 1 replaced with portable DML.
- AMD modules (report.js, student.js, reminder.js).
- Accessibility improvements for keyboard navigation and interactive controls (role, tabindex, aria-expanded).

## [1.2.0] - 2026-06-XX

### Added
- Topic dropdown, Multi-SCO second level, collapsible chart.
- Reminder checkboxes with dynamic counter.
- Auto-install block (adhoc task in batches of 200).
- PRG pattern in reminders.

## [1.1.0] - 2026-05-01

### Added
- First functional version: global report, detail, export, PDF, reminders.
