# Configuration

Swage reads its settings from `/home/swage/app/shared/.env` on the panel host. After changing a setting, rebuild the panel so it takes effect:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:update --force
```

Source control providers are configured on their own pages in the panel instead. See [Source control](/docs/source-control).

## Settings

| Variable | Default | Purpose |
|---|---|---|
| `SWAGE_ADMIN_EMAIL` | | Email of the administrator account the installer creates. |
| `SWAGE_ADMIN_NAME` | `Administrator` | Name of the administrator account. |
| `SWAGE_ADMIN_PASSWORD` | random | Password for the administrator account. Printed once when left empty. |
| `SWAGE_REGISTRATION` | `false` | Let anyone who can reach the panel create an account at `/register`. New accounts are never administrators. |
| `SWAGE_REQUIRE_TWO_FACTOR` | `true` | Require two-factor authentication for every user. |
| `SWAGE_CERTIFICATE_EMAIL` | owner's email | Email registered with Let's Encrypt for site certificates. |
| `SWAGE_SERVER_USER` | `swage` | The Unix user that owns sites and runs deployments on provisioned servers. |
| `SWAGE_RELEASES_TO_KEEP` | `4` | Zero-downtime releases kept per site for rollbacks. |
| `SWAGE_DEPLOYMENT_QUEUE` | `default` | Queue deployments run on. Installs use `deployments`, which has workers of its own. |
| `SWAGE_NODE_VERSION` | `22` | Node.js version installed on app servers. |
| `SWAGE_UPDATE_BRANCH` | `main` | Branch the panel's version tags are cut on. See [Updating](/docs/updating#the-release-branch). |
| `SWAGE_UPDATE_RELEASES_TO_KEEP` | `5` | Panel releases kept for rollbacks. |
| `SWAGE_HEALTH_CHECK_URL` | `APP_URL/up` | URL checked before a new panel release goes live. Installs behind a Cloudflare Tunnel use `http://127.0.0.1/up`. |
| `SWAGE_PLUGIN_INDEX` | the Swage plugin index | URL of the plugin index plugins are installed from. |
| `SWAGE_UNOFFICIAL_PLUGINS` | `true` | Let administrators install plugins straight from a git repository. Set to `false` to only allow plugins from the plugin index. |
| `SWAGE_AUTOMATIC_UPDATES` | `false` | Update Swage and then its plugins every day, and hide the update buttons. See [Automatic updates](/docs/updating#automatic-updates). |

## Email

Swage sends [notifications](/docs/notifications) by email with Laravel's `MAIL_` settings. A new install has `MAIL_MAILER=log`, which writes email to the log instead of sending it. See [Setting up email](/docs/notifications#setting-up-email).

## Installer variables

These are read by the installer, not the panel. See [Installing a fork](/docs/installation#installing-a-fork).

| Variable | Default | Purpose |
|---|---|---|
| `SWAGE_REPOSITORY` | `https://github.com/Swage-Deploy/swage-core.git` | The repository Swage is installed and updated from. |
| `SWAGE_BRANCH` | `main` | The branch its releases are tagged on. |
| `SWAGE_ADMIN_PASSWORD` | Generated | The administrator's password, at least 12 characters. See [Installing without prompts](/docs/installation#installing-without-prompts). |

## Paths on the panel host

| Path | Contents |
|---|---|
| `/home/swage/app/current` | The running release of the panel. |
| `/home/swage/app/releases` | Panel releases kept for rollbacks. |
| `/home/swage/app/shared/.env` | The panel's settings. |
| `/home/swage/app/shared/storage` | Logs, sessions and other storage shared by every release. |
| `/root/swage-install.log` | Output of the installer. |
| `/etc/swage/cloudflared.env` | The Cloudflare Tunnel token, when installed behind a tunnel. |
| `/etc/swage/swage.conf` | What the installer was given, such as the domain and repository, read by host migrations. |
| `/usr/local/sbin/swage-change-domain` | Moves the panel to a new domain, run as root. See [Changing the domain](/docs/changing-domain). |
| `/usr/local/sbin/swage-reboot` | The helper the panel uses to reboot its host once a reboot is required. |

## Artisan commands

Run these on the panel host as the `swage` user, such as `sudo -u swage php /home/swage/app/current/artisan swage:update`.

| Command | Purpose |
|---|---|
| `swage:update` | Update the panel. `--force` rebuilds the current version. |
| `swage:plugin:install <name>` | Install a plugin from the index. |
| `swage:plugin:update` | Update installed plugins. |
| `swage:plugin:list` | List installed plugins. |
| `swage:plugin:remove <name>` | Remove a plugin. |
| `swage:domain-changed` | Point quick deploy webhooks at the panel's domain. `swage-change-domain` runs it for you. |
| `swage:check-reboots` | Check which servers need a reboot. Runs every hour on its own. |
| `swage:refresh-certificates` | Read the new expiry dates of certificates that have renewed. Runs on its own. |
