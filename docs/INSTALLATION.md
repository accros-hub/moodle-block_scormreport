# Installation and upgrade guide — Course Report

## Before installing

- Check that your Moodle version is supported (Moodle 5.0 to 5.3, see `version.php`).
- Check the PHP version required by your Moodle release and that the PHP **OpenSSL** and **cURL** extensions are enabled.
- Check that the Moodle server can make outbound HTTPS requests to `clientes.accros.es` (license activation and refresh).
- Check that Moodle cron is running.
- Take a full backup of the Moodle code, database and moodledata.

## Install from the ZIP

1. Download the plugin ZIP from Moodle Marketplace (free *Trial* option or a paid license option).
2. Sign in as a site administrator.
3. Go to **Site administration → Plugins → Install plugins**.
4. Upload the ZIP and review Moodle's validation screen.
5. Continue and complete the installation in **Site administration → Notifications**.
6. Open **Site administration → Plugins → Blocks → Course Report** and review the settings.
7. Open **Manage license** and request the 30-day trial or activate your license key.

No other plugin, Composer step or command-line access is required.

## Add the block to courses

- Add the block to a course from the course page (edit mode → **Add a block → Course Report**).
- Or enable **Automatically add to new courses** in the settings.
- Or use **Add to all existing courses** in the settings. Test it first on a small set of courses.

## Upgrade

1. Back up the site.
2. Download the new version from Moodle Marketplace.
3. Install it with **Site administration → Plugins → Install plugins** (or replace the `blocks/scormreport` folder).
4. Open **Site administration → Notifications** to run the upgrade.
5. Review the plugin settings and the scheduled task logs.
6. Open a representative course report and test the exports.

Test upgrades on a copy of the site before upgrading production.

## Uninstall

Before uninstalling, export any report or reminder information that your organisation needs to keep. Use Moodle's standard process in **Site administration → Plugins → Plugins overview**. Uninstalling removes the block instances, the reminder log and the course hours custom field if the plugin created it. Do not delete plugin tables or settings by hand.

If the site has an active license, use **Remove license from this Moodle** on the license page before uninstalling, so the license can be activated on another site.

## Checks after installation

- The plugin appears under installed blocks.
- The block can be added to a test course.
- The report works for a teacher, an editing teacher, a manager and a student.
- PDF and Excel exports work.
- A manual reminder is delivered.
- Cron runs the plugin's scheduled tasks.
- The license page shows the license status and the last check.
- No PHP warnings appear with debugging set to DEVELOPER on a test site.
