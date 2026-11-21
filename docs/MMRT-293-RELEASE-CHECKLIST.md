# MMRT-293 — Marketplace review completion checklist

This file is a release checklist, **not** evidence that the checks have passed. Work only on a staging Moodle installation; do not deploy this review branch to a production site.

## Reviewer response / listing disclosure (English)

**Compatibility:** Moodle 5.0, 5.1, 5.2 and 5.3. PHP version must match the Moodle release. Requires PHP cURL and OpenSSL, outgoing HTTPS access to the licensing service, and working Moodle cron for scheduled tasks.

**Licensing:** The code is GPL v3 or later. Using Course Report requires a site-specific license key; a 30-day free trial is available. When a trial or paid license expires, reports for courses started before expiry remain available, while courses starting later are locked. Suspended or cancelled licenses lock all course reports. See README for detailed behavior.

**External service:** Course Report connects to `https://clientes.accros.es/api/v1/` for license activation, periodic refresh, renewal, release and trial requests. Requests include the Moodle site URL, Moodle release, plugin version and license key or signed token; the administrator-provided email address is sent when requesting a trial. The plugin does not send learner records, grades, SCORM activity or course contents to this licensing service. When the service is temporarily unavailable, an existing valid signed token continues to work according to its validity and refresh policy.

**Trademark:** Course Report is an independent product for Moodle™ and is not affiliated with or endorsed by Moodle Pty Ltd. The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates.

## Required test evidence

- [ ] Verify PHP syntax across all plugin PHP files (`find . -name '*.php' -print0 | xargs -0 -n1 php -l`).
- [ ] Run Moodle plugin CI / codechecker on the complete plugin and resolve findings.
- [ ] Run Moodle PHPUnit tests on staging: `vendor/bin/phpunit --testsuite block_scormreport_testsuite` if the suite is configured; otherwise run `vendor/bin/phpunit blocks/scormreport/tests/` from the Moodle root.
- [ ] Verify backup and restore of notification history, including user mappings.
- [ ] Compare student connection sessions, daily connections PDF and tutor activity before/after the refactor using historical records, a 30-minute gap, midnight and a 90-day window boundary.
- [ ] Verify compliance dashboard renders with course names containing HTML-special characters.
- [ ] Verify reminder preview, threshold selection, channel selection and checkbox selection in a staging course. Do not send to real users.
- [ ] Verify scheduled tasks and license activation/refresh with test credentials.
- [ ] Confirm GitHub Actions workflow succeeds on the final branch commit.
- [ ] Confirm Moodle 5.0–5.3 compatibility as claimed, or narrow the supported versions based on actual results.
- [ ] Add the trademark statement on the **public Accros documentation website**, not only this repository.
- [ ] Update Moodle Marketplace listing with compatibility, licensing and external-service disclosures above.
- [ ] Bump `version.php` and release number only after validation, package a ZIP with `scormreport/` as its top-level directory, and upload it to the existing MMRT-293 review.
- [ ] Keep `main` and production untouched until validation and approval.

## Reviewer-facing summary

The review branch addresses the identified global function names, GPL boilerplate, PHP variable naming, class autoloading, log query memory consumption, and HTML/JavaScript rendering. The log refactor uses 90-day **query windows**, not a 90-day retention cutoff; the complete history is processed. The existing notification backup/restore changes remain in the base release.

**Do not state that the review is complete until the staging tests and public website/Marketplace edits are verified.**
