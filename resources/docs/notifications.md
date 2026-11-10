# Notifications

Swage tells people when something on their servers needs them: a deployment fails, provisioning stops, a certificate stops renewing, a plugin raises an alert. Notifications show in the panel, can be sent by email, and can post to a server's Slack, Discord or other webhooks. They need Swage v1.3.16 or later.

## What you're told about

| Notification | When |
|---|---|
| **Deployment failed** | A deployment of a site fails. The site keeps running its last release. |
| **Provisioning failed** | The provisioning script stops with an error. |
| **SSH host key changed** | A server's SSH host key no longer matches the one Swage pinned, so Swage stopped connecting to it. Sent at most once a day per server. See [SSH host keys](/docs/servers#ssh-host-keys). |
| **Certificate not renewed** | A certificate has under 20 days left. Certbot renews at 30, so its renewal failed. Sent once per missed renewal. |
| **Reboot required** | An update needs a reboot to take effect. Sent once, until the server has rebooted. See [Reboots](/docs/servers#reboots). |
| **Plugin alerts** | A plugin raised an alert, such as the [Security scanner](/docs/plugins#security-scanner) seeing an SSH login after failed attempts. |
| **Swage update failed** | Updating the panel failed or was rolled back. Sent to administrators. |

## Who is told

A server's notifications go to its owner and its [Managers](/docs/members). A site's deployments and certificates also go to that site's Managers. Viewers aren't told, since they can't act on them. Panel update failures go to every administrator.

The server's **Notifications** tab lists who its notifications reach.

## In the panel

**Notifications** in the sidebar lists yours, newest first, with a red badge counting the unread ones. Choosing one marks it read and opens the page it's about. **Mark all read** clears the badge. Notifications are kept for 90 days.

## By email

Every notification is also emailed until you turn its type off. Under **Settings → Notifications**, the **Email** switch beside each type chooses whether it reaches you by email. It always shows in the panel. **Send a test notification** sends one to you both ways.

### Setting up email

A new install writes email to its log instead of sending it, so until an administrator sets up email the settings page says so and notifications only show in the panel. Set the `MAIL_` settings in `/home/swage/app/shared/.env` on the panel host to your mail provider's SMTP details:

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=swage@example.com
MAIL_PASSWORD=<password>
MAIL_FROM_ADDRESS=swage@example.com
MAIL_FROM_NAME=Swage
```

Then rebuild the panel so it takes effect:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:update --force
```

**Send a test notification** checks it works. If the email can't be sent, administrators see the mail server's error.

## Webhooks

A server's **Notifications** tab can post its notifications to Slack, Discord or any HTTPS URL. **Add a webhook** with a name, a type and the URL:

- **Slack**: an incoming webhook URL, from `hooks.slack.com`.
- **Discord**: a channel's webhook URL, from its **Integrations** settings.
- **JSON**: any `https://` URL. Swage posts each notification as JSON:

```json
{
    "type": "deployment_failed",
    "title": "Deploying example.com failed",
    "message": "The deployment of 1a2b3c4 to example.com failed, so the site keeps running its last release. Its output shows where it stopped.",
    "severity": "high",
    "url": "https://swage.example.com/servers/1/sites/2",
    "server": {"id": 1, "name": "production-1", "ip_address": "203.0.113.10"},
    "site": {"id": 2, "domain": "example.com"},
    "sent_at": "2026-11-04T12:00:00+00:00"
}
```

`severity` is `high`, `medium` or `low`, and `site` is `null` for notifications about the whole server.

Anyone with a webhook's URL can post to its channel, so Swage keeps it encrypted and only shows its host. URLs must use `https://` and can't point at a private or local address. **Test** posts a test notification. A webhook that fails isn't retried: the tab shows why it failed, and when it last posted.

The server's owner and Managers manage its webhooks. Viewers can see them.
