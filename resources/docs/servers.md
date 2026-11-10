# Servers

Swage manages fresh **Ubuntu 24.04** servers from any provider: a cloud VM, a dedicated server or a VM on your own hardware. It connects to them over SSH and does all of their setup itself.

## Connecting a server

From the dashboard, choose **New server** and pick a type.

### App servers

An app server hosts sites. Choose:

- **PHP versions.** Any of PHP 8.3, 8.4 and 8.5, installed side by side, each with its own tuned PHP-FPM pool. Each site picks the version it runs. More can be [added later](#changing-php-versions-later).
- **Default PHP version.** The version `php` runs on the command line. Its FPM pool gets the largest share of memory. It can be [changed later](#changing-php-versions-later).
- **Database.** MySQL 8, PostgreSQL 16, PostgreSQL 16 with pgvector, or none. The other engine, or pgvector, can be [added later](/docs/databases#adding-an-engine-later).
- **Redis.** Used for cache, sessions and queues.

App servers also get nginx, Node.js (version 22 by default), Composer, Supervisor and a firewall.

### Database servers

A database server runs only MySQL or PostgreSQL, with no nginx, PHP, Node.js or Redis, and gives the databases the memory PHP would have had. See [Database servers](/docs/database-servers).

### Addresses

For either type, enter:

- **Name**, such as `production-1`.
- **Public IP address**, which Swage connects to over SSH.
- **SSH port**, `22` unless you changed it.
- **Private IP address**, optional. If your provider has a private network, enter the server's address on it. Database servers listen on it, and app servers use it to reach them.

### Changing PHP versions later

The **PHP** panel on the server's overview page lists the installed versions and what uses each one. From it you can install any supported version the server doesn't have yet, make another version the default, or remove one.

When you install a version, Swage installs it beside the others with the same extensions, then writes its PHP-FPM pool and PHP settings, including any custom settings saved on the server's **Config** page. Each change is recorded on the server's **Activity** page.

Once it's installed, switch a site to it from the site's **Settings**. Swage moves the site's PHP-FPM pool to the new version and points its queue workers, Octane and scheduler at the new `php` binary. Its next deployment runs Composer and Artisan with it too.

Making a version the default points `php` on the command line, and Composer, at it, and gives its PHP-FPM pool the largest share of memory. Sites keep the version they chose, but daemons and scheduled jobs that run plain `php` move with the default.

A version can be removed once no site, daemon or scheduled job runs it, and the default can't be removed. Swage stops its PHP-FPM, uninstalls its packages and deletes `/etc/php/<version>`.

Each of these re-tunes every service, which restarts PHP-FPM, the databases and Redis. Only one runs at a time, and not while a database engine is being installed. They need Swage v1.3.8 or later, and the server's owner or a Manager.

## Provisioning

After you create the server, Swage shows a command. SSH into the fresh server as root and run it:

```bash
(command -v curl > /dev/null || (apt-get update -qq && apt-get install -yqq curl > /dev/null)) && f=$(mktemp) && curl -fsSL --proto '=https' 'https://swage.example.com/provision/<token>' -o "$f" && bash "$f"
```

Minimal images can lack `curl`, so the command installs it first when it is missing. Only the server's owner and its managers can see the command.

The script first reports the server's CPU, memory and disk back to the panel, with its SSH host key, which Swage pins before it ever connects. A server without an ed25519 host key gets one, and provisioning stops if it cannot report one. It then installs and tunes every service for that hardware, reporting each step as it goes. The server's page shows the progress live. Provisioning takes a few minutes.

The scripts run as root, so serve Swage over HTTPS before provisioning: the command then refuses to fetch them any other way. In production, Swage refuses to add servers until `APP_URL` is an `https://` address.

The URL contains a 64-character token. A command starts provisioning for 24 hours after it is made, and stops working once the server is active. After that, the server's page shows a new command. If you use [Cloudflare Access](/docs/cloudflare-access), `provision/*` must bypass it.

During provisioning, Swage:

- updates the system and turns on [automatic security updates](/docs/updating#operating-system-updates),
- creates the `swage` user and gives the panel's SSH key access to it and to root,
- turns off SSH password logins and enables a firewall that only opens SSH, plus HTTP and HTTPS on app servers,
- installs and configures the services you chose,
- records the server's SSH host key, so it can tell if the server is ever replaced.

Servers provisioned before Swage v1.3.5 still accept SSH passwords. To turn them off, add `PasswordAuthentication no` to a file in `/etc/ssh/sshd_config.d/`, such as `00-swage.conf`, then run `sshd -t && systemctl reload ssh`. Swage only ever logs in with its key.

Provisioning keeps running in the background if your SSH session ends. To follow it on the server, run `tail -f /root/swage-provision.log`.

If a step fails, the server's page shows the error, and the full output is on its **Activity** page and in `/root/swage-provision.log`. Fix the cause and run the command again.

If the server stops reporting progress for a while, such as after a reboot or when the script was stopped, the page says so. Choose **Start over** to get a new command, but only once the script is no longer running on the server.

## System monitor

The server's overview page shows its CPU, load, memory and disk use, each with a chart of the last 24 hours. Swage reads them over SSH every five minutes and keeps a week of readings. Hover over a chart to see a reading's time and value, or press **Refresh** for a reading now.

A reading turns amber at 75% and red at 90%. Load counts against the server's CPU cores, so a load of 2 on a two-core server reads as 100%. Disk is the root filesystem, and memory counts what the kernel can't reclaim, so page cache isn't included. If Swage can't reach the server, its charts show a gap and the page says when it was last read.

In a container, such as an LXC container on Proxmox, the load average is the host's, shared with everything else running there. Swage shows it as **Host load**, without a meter or warnings, so judge the container by its CPU instead. Its CPU, memory, disk and uptime are its own.

## Hardware-aware tuning

Every service on a server shares the same machine, so Swage budgets its memory in a fixed order:

1. About 10% of memory, between 256 MB and 1 GB, is set aside for the operating system and page cache.
2. MySQL's buffer pool, PostgreSQL's shared buffers and Redis are given a share of the memory. The share is smaller when more than one database engine runs on the server.
3. Whatever remains goes to PHP-FPM workers, sized for a typical Laravel worker. The default PHP version's pool gets the most; the others start workers on demand.

Swage also sizes nginx's workers and connections to the CPU cores, MySQL's connections to the number of PHP workers, PostgreSQL's work memory and parallel workers, OPcache, kernel limits and swap.

The server's overview page shows how its memory was divided.

### Re-optimizing

If you resize a server, press **Re-optimize** on its overview page. Swage reads the hardware again and rewrites the tuned config. Your own [configuration overrides](/docs/configuration-overrides) are kept.

## Reboots

Automatic security updates never reboot a server. Swage checks every hour whether one needs a reboot, such as after a kernel update, and shows **Reboot required** on its overview page with the packages that asked for it. Choose **Reboot now** when a minute or two of downtime suits you. Every service Swage installs starts again on boot.

## SSH host keys

Swage records each server's SSH host key when it is provisioned and refuses to connect if the key changes, because that is what a server being impersonated looks like. If you rebuilt the server yourself, open its overview page and **trust the new key**.

## SSH access

A server's **SSH** tab shows who can sign in over SSH as root and as each site's user, read live from the server: every key in each user's `authorized_keys`, and whether sshd lets the user in. Keys you add or remove outside Swage show up here too. Swage's own user isn't listed, since only the panel signs in as it.

- **Add an SSH key** to let its holder sign in as a user. Paste an OpenSSH public key, such as the contents of `~/.ssh/id_ed25519.pub`, and give it a name, or leave the name empty to use the key's comment. Removing a key takes it out of the user's `authorized_keys`. Sessions already open with it stay open.
- **Turn off SSH access** for a site's user to stop it signing in at all, even with its keys. Swage adds the user to `DenyUsers` in `/etc/ssh/sshd_config.d/01-swage-users.conf` and ends its open SSH sessions. The site keeps running and deploying as usual. A user turned off by the server's own sshd config shows as off, but only that config can turn it back on.

To keep everyone out but Swage, turn off the **SSH on** switch. sshd then lets only root in, and accepts only Swage's own key, from `/etc/ssh/swage/authorized_keys`, so Swage keeps managing the server wherever the panel runs. Users' keys are left in place and work again once SSH is back on. Site users' open SSH sessions end; open root sessions stay open until they finish. If Swage stops managing the server, SSH is turned back on before Swage's key is removed, so you aren't locked out. Only the server's owner can turn SSH off or on.

Swage signs in as root with its own key, which is marked **Swage** in root's list and can't be removed, and root's access can't be turned off. Only the server's owner can add or remove root's keys. Managers can manage the site users' keys and access, and viewers can only look.

## Notifications

A server's **Notifications** tab lists who its notifications reach and posts them to Slack, Discord or other webhooks. See [Notifications](/docs/notifications).

## Server plugins and members

A server's **Plugins** tab holds the settings of any [server plugins](/docs/plugins) that are installed, such as the [Security scanner](/docs/plugins#security-scanner). Its **Members** tab shares the server, and every site on it, with other people. See [Server and site members](/docs/members).

## Deleting a server

Only the server's owner can delete it. Deleting a server makes Swage stop managing it and removes Swage's SSH key from it. The machine and everything on it keep running until you destroy it with your provider.
