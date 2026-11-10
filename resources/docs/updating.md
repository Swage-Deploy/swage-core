# Updating

Swage updates itself with zero downtime, the same way it deploys your sites.

## Updating the panel

Open the **Updates** page. It shows the version you are running, the latest version, and the history of past updates. Choose **Update now** to start one.

Swage is released through **version tags**, such as `v1.4.0`, and an update installs the newest one, once its [signature](#signed-releases) checks out. Pre-releases such as `v2.0.0-beta` are never installed.

Or, on the panel host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:update
```

Each update:

1. clones the newest version tag into a new release beside the running one, and checks it was signed with a release key,
2. installs dependencies and builds assets, including every installed plugin at the commit it is at,
3. applies any new **host migrations**, which change the panel host itself,
4. runs database migrations,
5. checks the new release's health at `SWAGE_HEALTH_CHECK_URL`,
6. switches over and restarts the panel's queue workers.

If the new release fails to build or fails its health check, it never goes live and the previous release keeps running. The newest five releases are kept, set by `SWAGE_UPDATE_RELEASES_TO_KEEP`.

To rebuild the current version, such as after changing `.env`, pass `--force`:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:update --force
```

## Automatic updates

A panel installed with `--automatic-updates`, or with `SWAGE_AUTOMATIC_UPDATES=true`, updates itself every day at 03:00 UTC. [Swage Cloud](/docs/swage-cloud) panels work this way.

1. Swage updates to its newest version first, as described above.
2. The new release then updates every [plugin](/docs/plugins#updating) with a newer version, in a single rebuild.

If Swage's update fails, the previous release keeps running and the plugins wait for the next day. A day with nothing to install adds nothing to the history. Automatic updates show as **Automatic** under **Started by**.

On these panels the **Updates** page has no **Update now** button, and the **Plugins** page has no **Update** or **Update all** buttons. **Check for updates** still shows what the next update will install. To update straight away, run it on the panel host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:auto-update
```

## The release branch

Version tags are cut on the `main` branch, set by `SWAGE_UPDATE_BRANCH`. Only a [fork](/docs/installation#installing-a-fork) released from another branch needs to change it. Swage only installs tags that point to commits on that branch, and never one older than the version it is running.

## Signed releases

Swage's version tags, and the official plugins' version tags, are signed with a **release key**. Swage keeps the public keys it trusts in `resources/release-signers`, which ships with each release. An update or official plugin whose tag is not signed with one of those keys is refused and nothing changes, so taking over the repository, or the account that pushes to it, is not enough to ship code to your panel.

The file of the release that is running is the one checked, so the keys can only change through a release signed with a key Swage already trusts. Swage's releases are signed either by a maintainer's own key or by the release workflow's CI key, which only signs runs a maintainer approves.

A [fork](/docs/installation#installing-a-fork) signs its own releases: put your own public key in its `resources/release-signers`. With no keys in the file, tags are not checked, and updates follow the release branch's newest commit until it has version tags.

## Operating system updates

Ubuntu's unattended upgrades install security updates every day on the panel host and on every managed server. Swage adds the repositories Ubuntu leaves out by default, so updates also come from:

| Repository | Provides | Where |
|---|---|---|
| Ubuntu's archive | The system, nginx, MySQL, PostgreSQL, Redis | Panel host and servers |
| `ppa:ondrej/php` | PHP and its extensions | Panel host and app servers |
| NodeSource | Node.js | Panel host and app servers |
| Cloudflare | `cloudflared` | Panel hosts behind a [Cloudflare Tunnel](/docs/cloudflare-tunnel) |

Services restart as their packages update, which interrupts requests for about a second. Other package updates and Ubuntu release upgrades are left to you.

### Reboots

Nothing is ever rebooted automatically, because a reboot takes sites down.

- **Servers.** Swage checks every hour whether a server needs a reboot, such as after a kernel update. Its overview page then shows **Reboot required**, with the packages that asked for it and a **Reboot now** button.
- **The panel host.** The **Updates** page shows when the panel host needs a reboot, with a **Reboot now** button. The host reboots a minute later, and the panel is unavailable for a minute or two while it starts again. Running deployments and workers on the panel host are interrupted, so wait for any update to finish first.

The panel can only reboot its host through a root-owned helper, `/usr/local/sbin/swage-reboot`, which refuses unless Ubuntu has flagged that a reboot is required.
