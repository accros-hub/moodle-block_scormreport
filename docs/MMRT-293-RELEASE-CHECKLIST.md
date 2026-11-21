# MMRT-293 — Marketplace review completion checklist

This file is a release checklist, **not** evidence that the checks have passed. Work only on a staging Moodle installation; do not deploy this review branch to a production site.

## Reviewer response / listing disclosure (English)

**Compatibility (fill from the final test results):** List only the Moodle releases on which the final candidate passed validation. The declared range in `version.php` is not test evidence. Use the PHP version required by your Moodle release, with cURL and OpenSSL available. The plugin needs outgoing HTTPS for licensing and a working Moodle cron for scheduled tasks.

**Licensing:** The code is licensed under GPL v3 or later. Course Report functionality requires a site-specific commercial license or a free trial license. The advertised trial is 30 days. The exact expiry comes from the signed token issued by the licensing server and should be confirmed before publication. After expiry, eligible courses that started on or before the expiry remain accessible. Courses starting later are locked. A suspension or cancellation blocks access to all courses.

**External service:** Course Report sends HTTPS requests to `https://clientes.accros.es/api/v1/` by default. Activation sends the product identifier, Moodle site URL, Moodle release, plugin version and license key. A trial request sends the same site and product fields plus the email supplied by the administrator, in place of the key. Refresh sends the site and product fields plus the signed token and can pick up a renewal. Release sends the signed token alone. These requests do not include learner records, grades, SCORM activity or course contents. If the service is temporarily unavailable, local verification of the signed token continues and the expiry rules still apply.

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

Draft reply for MMRT-293. Append final test results and public listing URLs before sending:

1. **Backup and restore:** Notification history is included when user data is selected. Restore processes individual notification entries and uses Moodle's mapped recipient and destination course. An earlier Moodle 5.3+ test restored exactly one notification for the correct user. The final candidate still needs a staging rerun.
2. **Frankenstyle:** Plugin-owned global helpers use the `block_scormreport_` prefix, including the PDF helpers and export callbacks.
3. **Log queries:** Student connections, the daily connections report and tutor activity process complete history in bounded query windows. Session state carries across windows. Synthetic comparisons against the original code matched for these reports, including a repeated recipient ID across query batches.
4. **Output:** Compliance and reminder views use Mustache. Reminder filter and channel changes are handled by the AMD module. The deployed AMD and source map are updated together.
5. **PHP naming:** The identified local variables in the report and task code use Moodle-style names.
6. **Autoloading:** Scheduled tasks and normal entry points use Moodle's autoloader for the license class. The library of global report helpers and Moodle's backup loading mechanism retain their required includes.
7. **GPL:** The PDF pagination helper now has the Moodle GPL boilerplate and copyright attribution. The reminder AMD source and build include copyright and license metadata.
8. **Trademark:** The repository README credits Moodle trademarks and identifies Course Report as independent. The public documentation page still needs the same attribution.
9. **CI:** GitHub Actions checks PHP and AMD syntax and whitespace, and defines isolated PostgreSQL jobs for Moodle PHPUnit, Moodle code style and Mustache validation. Report the final run before claiming that the matrix passed.
10. **Compatibility and licensing:** Confirm the final tested Moodle versions and trial policy before updating the Marketplace listing. The English disclosure above distinguishes the GPL code license from the site-specific service license.
11. **External service:** The disclosure above lists the endpoint and fields sent for activation, refresh, release and trial requests, and explains offline and expiry behavior.

**Do not state that the review is complete until the staging tests and public website/Marketplace edits are verified.**
