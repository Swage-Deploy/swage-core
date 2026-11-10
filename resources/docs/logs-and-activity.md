# Logs and activity

## Server logs

A server's **Logs** page reads logs live from the server:

- nginx's access and error logs,
- each PHP version's FPM log,
- the MySQL, PostgreSQL, Redis and Supervisor logs,
- the log of each daemon, queue worker and scheduled job,
- the system log, logins and sudo from `auth.log`, and the provisioning log.

Known secrets, such as database passwords, are masked before the log reaches your browser.

## Site logs

Each site's **Logs** page shows its own logs, such as the Laravel logs in `storage/logs`, the site's own nginx access and error logs, and the logs of its queue workers.

## Deployment output

Every deployment keeps the full output of its deploy script, along with the commit it deployed and who triggered it. Open a deployment from the site's page to read it.

## Activity

A server's **Activity** page lists every script Swage has run on the server, from provisioning to installing a certificate or saving a config override, with:

- what triggered it,
- when it ran and how long it took,
- whether it succeeded,
- its full output.

When something fails, the Activity page is the first place to look.
