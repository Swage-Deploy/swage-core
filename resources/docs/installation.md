# Installation

The panel is installed by a single script, run as root on a fresh Ubuntu 24.04 server. It sets up the panel and hardens the server it runs on.

> **Note:** Rather not run the panel's server yourself? [Swage Cloud](/docs/swage-cloud) hosts a panel for you on your own domain.

## Requirements

- A fresh **Ubuntu 24.04** server for the panel. Use a server of its own: the panel host is not meant to also host sites.
- A domain name for the panel, such as `swage.example.com`.
- Your SSH public key in the panel host's `/root/.ssh/authorized_keys`. The installer turns off password logins and refuses to run without a key in place, so you cannot lock yourself out.

## Point the domain at the server

Unless you are installing [behind a Cloudflare Tunnel](/docs/cloudflare-tunnel), create an `A` record (and an `AAAA` record if the server has IPv6) for the panel's domain pointing at the server first. The installer requests a Let's Encrypt certificate for the domain, which only works once the domain resolves to the server.

## Run the installer

As root on the server, download the installer and run it:

```bash
apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/install-swage.sh -o install-swage.sh && bash install-swage.sh
```

The installer asks for:

1. **The domain** Swage is served from, such as `swage.example.com`.
2. **An email address**, used for the administrator account and for Let's Encrypt notices.

It checks that the domain resolves to the server and warns you if it does not, because the certificate step would fail. It then installs the **newest version tag** of Swage, such as `v1.4.0`, and future updates follow version tags too. See [Updating](/docs/updating).

When it finishes, the installer prints the administrator's password **once**. Store it in your password manager.

## The install log

Everything the installer prints is also saved to `/root/swage-install.log`, readable by root only. It includes the administrator password, so delete the file once the password is stored somewhere safe:

```bash
rm /root/swage-install.log
```

## What the installer sets up

The panel host is configured by **host migrations** in `resources/scripts/host/migrations`. They run in order during the install, and any new ones run again on every update, so an existing panel host gets the same changes as a new one.

On the panel host, the installer:

- installs nginx, PHP, Node.js, Redis, Supervisor, and MySQL for the panel's own database,
- creates the `swage` user, which owns the panel at `/home/swage/app`,
- deploys the panel as a zero-downtime release under `/home/swage/app/releases`, with its `.env` and storage in `/home/swage/app/shared`,
- starts queue workers for the panel's background jobs, including separate workers for deployments,
- turns off SSH password logins and enables a firewall, fail2ban, unattended security upgrades and kernel hardening,
- requests a Let's Encrypt certificate for the domain, unless installing behind a Cloudflare Tunnel,
- creates the administrator account.

## If the install stops part way

Look at the end of `/root/swage-install.log` for the error, fix the cause, and run the installer again with the same command. It fetches the newest release, with any fixes made since, and continues from the first host migration that has not been applied, so nothing is done twice.

Once the panel is installed, the installer refuses to run again. Update the panel instead.

## Installer options

| Option | Purpose |
|---|---|
| `--cloudflare-tunnel-token <token>` | Serve the panel through a Cloudflare Tunnel instead of opening ports 80 and 443. See [Cloudflare Tunnel](/docs/cloudflare-tunnel). |
| `--domain <domain>` | The domain Swage is served from, instead of asking for it. |
| `--disable-unofficial-plugins` | Only allow plugins from the plugin index. **Install from a repository** is hidden and `swage:plugin:install --url` is refused. |
| `--automatic-updates` | Update Swage and then its plugins every day, instead of from the **Updates** and **Plugins** pages. See [Automatic updates](/docs/updating#automatic-updates). |
| `--email <email>` | The administrator's email address, also used for Let's Encrypt notices, instead of asking for it. |

### Installing without prompts

To install from a script, such as over SSH from your own tooling, pass both `--domain` and `--email`. You can also choose the administrator's password with the `SWAGE_ADMIN_PASSWORD` variable, at least 12 characters, instead of having one generated and printed:

```bash
SWAGE_ADMIN_PASSWORD='<password>' bash install-swage.sh --domain swage.example.com --email you@example.com
```

The password only reaches the step that creates the administrator. It is not written to `.env`, the install log or anywhere else on the server.

Without a terminal, the installer never waits for an answer. Where it would ask a question, such as when the domain does not resolve to the server yet, it stops with an error instead.

## Installing a fork

To install your own fork of Swage, set these when you run the installer:

| Variable | Purpose |
|---|---|
| `SWAGE_REPOSITORY` | The fork's git URL. A public fork can use `https://`. A private fork uses SSH, such as `git@github.com:you/swage.git`. |
| `SWAGE_BRANCH` | The branch the fork's releases are tagged on, if it is not `main`. |

```bash
SWAGE_REPOSITORY=git@github.com:you/swage.git bash install-swage.sh
```

For a private fork, the installer creates a deploy key and prints it. Add it to the repository as a **read-only** deploy key and press Enter to continue. The panel then updates from the fork.

A fork's updates are checked against the keys in its own `resources/release-signers`, so replace Swage's key there with your own and sign your version tags with it. See [Signed releases](/docs/updating#signed-releases).

## Next steps

Log in and follow [First steps](/docs/first-steps).
