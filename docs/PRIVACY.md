# Privacy and data processing — Course Report

## Data processed inside Moodle

Course Report reads Moodle course, enrolment, user, log and SCORM data to build the reports. Depending on the report and export, this includes learner identity, course membership, SCORM attempts, SCO status, score, reported time, access dates, test answers and, in the connection reports, IP addresses.

The plugin stores:

- **Reminder log** (`block_scormreport_notif`): recipient, course, channel, reason, progress, threshold, status, sender and time.
- **User preference** (`block_scormreport_columns`): the optional report columns chosen by each user.

Both are declared in the Moodle Privacy API and are included in data exports. Deletion requests remove the reminders received by the user and anonymise the reminders the user sent.

## Reports with IP addresses

The daily connection PDF and the Tutor activity view show IP addresses and connection dates. This is personal data: restrict access to authorised staff, define a retention period for exported files and handle them securely.

## Data sent to the Accros licensing server

The plugin connects to `https://clientes.accros.es/api/v1/` only to request a trial and to activate, check, renew or remove the license. It sends:

- the Moodle site address (`wwwroot`);
- the Moodle release and the plugin version;
- the license key (when activating) or the signed license token (when checking or removing);
- the email address entered by the administrator, only when requesting the trial.

No data about learners, tutors, courses, grades or SCORM activity is sent to the licensing server. Accros Business Solutions uses the trial email address only to send and manage the trial license.

## Responsibilities of the site operator

The organisation running the Moodle site remains responsible for the legal basis of the processing, informing learners and staff, configuring role permissions, controlling exports and access to IP addresses, setting retention periods and answering data subject requests.
