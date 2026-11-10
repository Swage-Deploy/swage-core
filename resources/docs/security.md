# Security

Swage holds the SSH keys to every server it manages, so it is built to be careful with them, and with your servers.

## The panel

- **Two-factor authentication** is required for every user by default, set by `SWAGE_REQUIRE_TWO_FACTOR`. Passkeys are supported.
- **Registration is off** by default. Accounts created when it is on are never administrators.
- **Each user only sees their own servers**, plus servers and sites they are a [member](/docs/members) of. Viewers cannot change anything or read a site's environment.
- **Administrators** additionally manage users, plugins, source control providers and updates. The Users and Plugins pages are behind a password confirmation. Administrators can see every server, manage its members and export its databases, but only change servers they own or are a Manager of. See [Users](/docs/users#who-can-do-what).
- **Signing in as a user** is limited. Administrators cannot sign in as other administrators, and while signed in as someone they cannot change that user's password, two-factor authentication, passkeys or connected accounts. Every impersonation is logged. See [Users](/docs/users#signing-in-as-a-user).
- **API keys act as their user**, with no more access than the user has, limited to the areas chosen for each key and optionally set to expire. Only a hash of each key is stored. See [API](/docs/api#api-keys).
- **Source control acts as the server's owner only.** Members cannot install repositories through the owner's connected account or change its webhooks.
- **Secrets are encrypted** in the database: SSH keys, deploy keys, provider tokens, provider settings, database passwords, deploy tokens and webhook secrets.
- **Public endpoints are guarded.** Provisioning callbacks need a 64-character token that expires once the server is active, deploy URLs need a 48-character token, webhooks are verified with a secret per site, and the API needs an API key. All four are rate limited.
- **Plugins only install from the plugin index**, pinned to the commit they were installed at, and checked against that commit before every rebuild.

You can put the panel behind [Cloudflare Access](/docs/cloudflare-access) as an extra layer.

## The panel host

The installer gives the panel host:

- key-only SSH, with password logins turned off,
- a firewall that opens only SSH, HTTP and HTTPS, or only SSH behind a [Cloudflare Tunnel](/docs/cloudflare-tunnel),
- fail2ban,
- [automatic security updates](/docs/updating#operating-system-updates), including PHP, Node.js and `cloudflared`,
- kernel hardening,
- one narrow sudo rule, so the panel can reboot its host from the **Updates** page only once a reboot is required.

## Managed servers

- **Key-only SSH**, with password logins turned off. Servers provisioned before Swage v1.3.5 still accept passwords: see [Provisioning](/docs/servers#provisioning) to turn them off.
- **A firewall** that opens only SSH, plus HTTP and HTTPS on app servers. Database ports open only to the addresses you allow.
- **Automatic security updates**, including PHP and Node.js, with a notice when a reboot is needed.
- **Pinned host keys.** Swage refuses to connect when a server's SSH host key changes, until you trust the new one.
- **Restricted sudo.** Sites can only reload PHP-FPM and restart their own workers, through root-owned helpers.
- **Root-owned daemon logs**, so a site cannot trick Supervisor into writing to other files.
- **nginx runs as its own user**, so sites cannot read each other's files through it.
- **A database user per site**, limited to its own database.
- **Redis requires a password**, and every site gets a private Redis of its own, so no other site can reach its cache, sessions or queues.
- **nginx only follows links to files their owner owns**, so a site cannot serve another site's files by linking to them.
- **Visitors' real addresses behind Cloudflare.** For sites proxied by Cloudflare, nginx takes the visitor's address from Cloudflare's header, so logs, rate limiting and the app see the visitor, not Cloudflare.

To watch servers for brute force attempts and probing, and block the addresses behind them, install the [Security scanner](/docs/plugins#security-scanner) plugin. The [AI review](/docs/plugins#ai-review) plugin can also have an AI model of your choice review each server's security, performance and reliability and suggest what to fix, without ever changing the server.

## Site isolation

Every site on a server [runs as its own Linux user](/docs/sites#site-isolation), with its own PHP-FPM pool, deploy key, workers and, on servers with Redis, a private Redis.

## Reporting a vulnerability

Please report vulnerabilities privately through the Swage repository's security advisories on GitHub rather than in a public issue.
