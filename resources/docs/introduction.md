# Introduction

Swage is a self-hosted control panel for running Laravel and PHP apps on your own Ubuntu servers. You connect a fresh server, Swage provisions and tunes it to the hardware it finds, and you then manage its sites, databases, deployments, workers, scheduled jobs and service config from the browser.

Swage itself is a Laravel application, built with Livewire and Flux. It runs on a server of its own, the **panel host**, and connects to every server it manages over SSH.

## What Swage does

- **Provisions servers.** Point Swage at a fresh Ubuntu 24.04 server and it installs nginx, PHP, Node.js, your database and Redis, sets up a firewall, and locks down SSH.
- **Tunes them to the hardware.** CPU cores, memory and disk are detected while the server is provisioned. PHP-FPM workers, the MySQL buffer pool, PostgreSQL's shared buffers, Redis memory, nginx workers and swap are all sized to fit.
- **Deploys your apps.** Sites deploy from GitHub or GitLab through their plugins, or from any git host over SSH. Deployments can build each release beside the live one and switch over atomically, so no request is dropped.
- **Runs your background work.** Queue workers and other daemons run under Supervisor, and scheduled jobs run from cron, each with its own log.
- **Lets you change the config safely.** Add your own nginx, PHP, MySQL, PostgreSQL and Redis settings. Swage validates them first and restores the previous config if the service does not come back healthy.
- **Shares servers with your team.** Add people as administrators or users, and give them a whole server or a single site as a Manager or a Viewer.
- **Runs one-off commands.** Run Artisan commands such as `migrate --force` in a site's live release, and export or restore MySQL databases, from the browser.
- **Grows with plugins.** Git hosts such as GitHub and GitLab, site services such as LaraOwl and Laravel Nightwatch, and server tools such as the Security scanner and AI review come as plugins, installed and updated from the dashboard.
- **Has an API.** Deploy from CI, or script servers and sites, with API keys limited to the areas each one needs.
- **Keeps itself up to date.** The panel updates itself to each new version tag with zero downtime from its **Updates** page, and rolls back if a new release fails its health check.

## Two kinds of servers

| Kind | Runs | Use it for |
|---|---|---|
| **App server** | nginx, PHP-FPM 8.3, 8.4 and 8.5 side by side, Node.js, and optionally MySQL or PostgreSQL and Redis | Hosting sites. A single app server with its own database is all most projects need. |
| **Database server** | MySQL and/or PostgreSQL only, tuned to use most of the memory | Giving one or more app servers a database of its own. |

## How the pieces fit

```text
            you ──https──▶  Swage panel  (panel host)
                               │
                               │  SSH, as root and as the site users
                               ▼
     ┌───────────────┐   ┌───────────────┐   ┌─────────────────┐
     │  app server   │   │  app server   │   │ database server │
     │ nginx · PHP   │   │ nginx · PHP   │◀──│ MySQL / Postgres│
     │ Redis · MySQL │   │ Redis         │   │                 │
     └───────────────┘   └───────────────┘   └─────────────────┘
```

Servers call back to the panel while they are provisioned, and git hosts call it to trigger deployments, so the panel must be reachable from the internet, either directly or through a [Cloudflare Tunnel](/docs/cloudflare-tunnel).

## Where to start

1. [Install the panel](/docs/installation) on a fresh Ubuntu 24.04 server with one command.
2. Follow [First steps](/docs/first-steps) to log in, set up two-factor authentication and connect a git host.
3. [Connect a server](/docs/servers) and [create a site](/docs/sites).
4. [Add your team](/docs/users) and share servers or sites with them as [members](/docs/members).
