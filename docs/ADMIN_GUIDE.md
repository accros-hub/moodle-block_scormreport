# Administrator guide — Course Report

Course Report gives authorised staff a consolidated view of learner progress in SCORM activities. Use it as a reporting aid; it does not replace the SCORM package itself, Moodle completion rules or your organisation's formal training records.

## Settings

Open **Site administration → Plugins → Blocks → Course Report**.

| Setting | What it does | Default |
|---|---|---|
| Pass threshold (%) | Percentage of individual SCOs passed that marks a learner as above the threshold. This is not Moodle course completion. | 75 |
| Course hours field | Short name of the course custom field with the course hours. Make sure the field exists and is filled in consistently. | `hours` (an existing `horas` field is reused on installation) |
| Default reminder channel | Email, Moodle message or both. Delivery also depends on Moodle's outgoing mail and messaging settings. | Moodle message |
| Deadline tolerance (points) | Margin used by the deadline status (on track / tight / at risk). | 15 |
| Automatic reminders | Enable them, days of inactivity and progress threshold. | Off; 7 days; 75 % |
| Automatically add to new courses | Adds the block to every new course. | Off |
| Add to existing courses | Adds the block to every existing course. Test on a small sample first. | Button |
| Training Compliance dashboard access | Roles that see all courses and roles that see only their own courses. | — |
| Tutor roles | Roles listed in the Tutor activity view. | Teacher roles with or without editing rights |

## Roles and capabilities

Review the capabilities in **Site administration → Users → Permissions → Define roles** and, where needed, with course-level overrides.

| Capability | Allows |
|---|---|
| `block/scormreport:addinstance` | Add the block to a course |
| `block/scormreport:myaddinstance` | Add the block to the dashboard |
| `block/scormreport:viewreports` | View the course report and the Tutor activity view |
| `block/scormreport:viewownreport` | View own progress |
| `block/scormreport:export` | Export Excel and PDF reports |
| `block/scormreport:sendreminders` | Send reminders |
| `block/scormreport:viewcompliance` | View the Training Compliance dashboard |

Reports contain personal data. Grant these capabilities only to roles that need them, and check them again after customising roles or upgrading.

## Scheduled tasks and cron

- **Send automatic Course Report reminders:** daily at 07:00 server time, when automatic reminders are enabled.
- **Refresh Course Report license:** hourly; the licensing server is contacted only when a check is due.

Check **Site administration → Server → Tasks → Scheduled tasks** and the task logs if reminders or license checks do not run.

## License administration

Open **Manage license** from the plugin settings. From this page you can:

- request the free 30-day trial (an email address is required);
- activate a license key bought in Moodle Marketplace;
- check the license now;
- remove the license from this Moodle site.

Keep the license key confidential. The license is bound to the Moodle site address (`wwwroot`). To move to a new server, remove the license on the old site and activate it on the new one. Do not copy a production database into a public test site without considering this.

When the license or trial ends, courses that started on or before the end date keep their report; courses that start later are locked until a license is activated. If the license is suspended or cancelled, every course is locked. No data is deleted.

## Reading the figures

See "Understanding the report figures" in the README before using the reports for audits or as formal evidence. In particular, the score is a mean of SCO scores and may differ from the package's weighted grade, and the threshold status is not Moodle course completion.

## Privacy and retention

The plugin stores a reminder log and can generate PDF reports with learner and connection data, including IP addresses. Decide who may view and export these reports, how long exported files are kept, and how access and deletion requests are handled. See [PRIVACY.md](PRIVACY.md).
