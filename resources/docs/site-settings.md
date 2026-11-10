# Site settings

Each site has tabs for its deployments, SSL, queues and scheduler, nginx, PHP, commands, logs, members, plugins and settings.

Viewers see every tab except **Commands**, and cannot change anything or read the environment, which holds the site's secrets. See [Server and site members](/docs/members).

## Environment

The **Environment** page edits the site's `.env` file, which every release shares. It holds the site's secrets, such as its database password and app key, so it stays hidden until you choose **Reveal environment**. Turn on **Run config:clear after saving** to clear the cached config, so the app reads the new values straight away. Otherwise they are picked up at the next deployment.

## SSL certificates

On the **SSL** page, obtain a free certificate from Let's Encrypt for the site's domain and aliases. Every domain must already point at the server, because Let's Encrypt checks each one over HTTP. Certificates renew automatically on the server, and nginx redirects HTTP to HTTPS once one is installed.

The email registered with Let's Encrypt is the server owner's, unless `SWAGE_CERTIFICATE_EMAIL` is set.

Certbot renews certificates 30 days before they expire. If one gets closer to expiry than that, its renewal is failing: the SSL page and the server's overview page say so. Check that every domain still reaches the server over HTTP, including `/.well-known/acme-challenge/`, which Cloudflare's bot protection can block. **Obtain certificate** again also renews it.

### Behind Cloudflare's proxy

Obtain the certificate with the domain set to **DNS only** (the grey cloud), or with Cloudflare's SSL/TLS mode on **Flexible**. Once it is installed, set the SSL/TLS mode to **Full (strict)**. Leaving it on Flexible makes Cloudflare connect over HTTP, which nginx redirects to HTTPS, so visitors get stuck in a redirect loop.

## Queues and scheduler

The **Queues & scheduler** page manages the site's queue workers and turns the Laravel scheduler on or off. See [Daemons and scheduled jobs](/docs/daemons-and-scheduler).

## nginx

The **nginx** page adds directives and `location` blocks to the site's `server` block. Swage's own lines that yours replace are shown struck through. The config is checked with `nginx -t` before it is applied, and the old config is restored if the check fails.

Only the server's owner and its Managers can change it. nginx reads it as root, so people who are members of just this site can see it but not edit it.

```nginx
location /downloads/ {
    add_header Cache-Control "public, max-age=86400";
}
```

## PHP

The **PHP** page changes PHP's settings for this site's web requests only. Leave a field empty to use the server's setting.

Every site has a [PHP-FPM pool of its own](/docs/sites#site-isolation), where these settings are written. Octane sites are not served by PHP-FPM, so they take their settings from the server's `php.ini`. Change those in the server's [configuration overrides](/docs/configuration-overrides).

| Setting | Notes |
|---|---|
| **Memory limit** | Such as `512M` or `1G`, or `-1` for no limit. |
| **Max upload size** | Also sets `post_max_size` and nginx's `client_max_body_size`. |
| **Max execution time** | In seconds, up to 3600. nginx waits at least this long for PHP. |
| **Max input vars** | Such as `5000`. |
| **Max workers** | The most requests the site's pool handles at once (`pm.max_children`). Others wait until a worker is free. Empty uses the limit the server sized from its memory, shown in the field. |
| **Other settings** | Any other setting, one `name = value` per line, such as `display_errors = Off`. |

Raise **Max workers** when the site's pool keeps reaching its limit and the server has memory to spare. Each worker can use about 64 MB, and the page warns when the pool could use more than the memory the server sets aside for PHP. A pool that fills up often can also mean slow requests, such as slow queries or calls to other services without a timeout, so check those first.

Queue workers and the scheduler keep the server's CLI settings. Change those in the server's [configuration overrides](/docs/configuration-overrides).

## Commands

The **Commands** tab runs an Artisan command in the site's live release, as the site's user and with its PHP version. Type what follows `php artisan`, such as `migrate --force` or `optimize:clear`, and choose **Run**. The output appears as it runs, like a deployment's.

- The site must be deployed first.
- Commands run without asking questions, as if given `--no-interaction`.
- Commands that keep running or wait for input, such as `tinker`, `queue:work` or `horizon`, are refused. Run those as a [daemon](/docs/daemons-and-scheduler), or over SSH.
- Commands stop after about 14 minutes.

The **History** lists the last 25 commands, who ran them and their exit code. Commands and their output are kept for 90 days.

Only the server's owner and Managers of the server or site see this tab. A command runs with all of the site's access, so Viewers cannot run one.

## Settings

### Domains, web directory and PHP version

Change the site's aliases, web directory and PHP version. Changing the PHP version moves the site to that version's PHP-FPM, and the next deployment builds with it.

To change the site's main domain, choose **Change** beside it. The old domain stays on as an alias unless you untick the box to keep it, and an alias can be made the main domain. `APP_URL` follows the new domain when it named the old one. The site's files stay in the directory named after the domain it was created with, and its nginx config, logs and certificate keep that name too, so nothing moves and its workers keep running. A site with a certificate gets a new one that covers the new domain, so point the new domain at the server first. The old certificate keeps serving until the new one is issued.

### Private Redis

Every site on a server with Redis has a **private Redis** server of its own, reached over a Unix socket only its user can open. Swage writes the socket into the site's `.env`, so no other site can read its cache, sessions or queues. The **Settings** tab shows the socket.

### Laravel Octane

Turn on **Serve with Octane (FrankenPHP)** to serve the site with Laravel Octane instead of PHP-FPM. Swage runs Octane as a daemon and has nginx proxy to it, and each deployment reloads Octane's workers.

- The application must require `laravel/octane`.
- Deploy the site at least once before turning it on.
- Octane is not available for zero-downtime sites.

### Deleting the site

See [Deleting a site](/docs/sites#deleting-a-site).

## Plugins

The **Plugins** tab holds the settings of any [site plugins](/docs/plugins) that are installed, such as:

- [**LaraOwl**](/docs/plugins#laraowl), which reports requests, exceptions, queries and jobs to your LaraOwl server,
- **Nightwatch**, which reports the site to Laravel Nightwatch through an agent Swage runs and restarts for the site.

An administrator installs them from the **Site** tab of the **Plugins** page.

## Members

The **Members** tab shares just this site with other people. See [Server and site members](/docs/members).

## Logs

The **Logs** page shows the site's Laravel logs, its nginx access and error logs, and its queue workers' logs. See [Logs and activity](/docs/logs-and-activity).
