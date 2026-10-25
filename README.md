<p align="center">
    <img src="public/favicon.svg" width="72" height="72" alt="Swage">
</p>

<h1 align="center">Swage</h1>

<p align="center">
    Self-hosted server management for Laravel and PHP apps.
    <br>
    <a href="https://swagedeploy.app"><strong>swagedeploy.app</strong></a> · <a href="https://swagedeploy.app/docs">Documentation</a>
</p>

---

Connect a fresh Ubuntu server and Swage provisions it, tunes it to its hardware, and gives you a dashboard for its sites, databases, deployments, workers and config. It runs on your own infrastructure and reaches your servers over SSH.

## Features

- **App and database servers**: nginx, PHP 8.3–8.5 side by side, Node.js, MySQL 8, PostgreSQL 16 with pgvector, and Redis, or dedicated database servers for app servers to share.
- **Tuned to the hardware**: PHP-FPM workers, database buffers, Redis memory, nginx and swap sized from the CPU, memory and disk Swage finds.
- **Zero-downtime deployments**: from GitHub, GitLab or any git host over SSH, with deploy keys, push to deploy and deploy trigger URLs for CI.
- **Sites**: isolated system users and PHP pools, Let's Encrypt, Laravel Octane, per-site PHP settings, custom nginx, `.env` editing and running Artisan commands.
- **Workers, schedules and logs**: Supervisor daemons, cron jobs and live logs, with a record of every script run on a server.
- **Safe config changes**: your own nginx, PHP, MySQL, PostgreSQL and Redis settings, validated and rolled back if a service fails to come back.
- **Teams**: users with two-factor authentication and passkeys, and server or site members as Managers or Viewers.
- **Secure by default**: key-only SSH, firewalls, fail2ban, restricted sudo and daily security updates.
- **Self-updating**: Swage and its plugins update from the dashboard through version tags, with zero downtime and automatic rollback.
- **Plugins**: add git hosts, server services and site integrations such as [LaraOwl](https://github.com/Swage-Deploy/swage-plugin-laraowl) and [Nightwatch](https://github.com/Swage-Deploy/swage-plugin-nightwatch).

## Requirements

- A fresh **Ubuntu 24.04** server for the panel, and a domain pointed at it
- Fresh Ubuntu 24.04 servers to manage
- Your SSH public key in the panel server's `/root/.ssh/authorized_keys`

## Installation

Point your domain at the server, then run this as root on it:

```bash
apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/install-swage.sh -o install-swage.sh && bash install-swage.sh
```

The installer asks for the panel's domain and an email address, installs the newest release, and prints the administrator password once.

To serve the panel through a Cloudflare Tunnel instead of open ports, end the command with your tunnel's token:

```bash
... && bash install-swage.sh --cloudflare-tunnel-token <token>
```

Then log in, set up two-factor authentication, and install a source control plugin from **Plugins**. The [documentation](https://swagedeploy.app/docs) covers installation, Cloudflare Access, connecting servers, members, plugins and configuration in full.

## Updating

Update from the **Updates** page, or on the panel server:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:update
```

Updates install the newest version tag. Each builds a new release beside the running one and only goes live once it passes a health check.

## Plugins

Plugins are Composer packages installed from the [plugin index](https://github.com/Swage-Deploy/plugin-repo) on the **Plugins** page. See the [documentation](https://swagedeploy.app/docs) for writing your own, or start from the [GitLab](https://github.com/Swage-Deploy/swage-plugin-gitlab) or [LaraOwl](https://github.com/Swage-Deploy/swage-plugin-laraowl) plugin.

## Development

Swage is a standard Laravel application built with Livewire and Flux:

```bash
composer run setup
composer run dev
```

Set `SWAGE_ADMIN_EMAIL` in `.env` and run `php artisan db:seed` to create an administrator account. Run the tests with:

```bash
php artisan test --compact
```

Some tests check generated server scripts with `bash -n`, and are skipped where `bash` is not available.

## License

Copyright (c) 2026 Node Dog Consulting. Swage is released under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version of Swage for other people over a network, you must offer them its source.
