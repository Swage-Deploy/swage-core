# Configuration overrides

Swage writes tuned config for every service it installs. To change a setting, add an **override** from the server's **Config** page rather than editing the files over SSH: overrides survive re-optimizing, and a bad one is rolled back before it can take the server down. Only the server's owner can change them, since the services read them as root. Managers can see them.

## Services you can configure

| Service | Where your config goes |
|---|---|
| **nginx** | Directives and blocks for the `http` block of `nginx.conf`. For a single site, use the site's nginx page instead. |
| **PHP-FPM php.ini** | `php.ini` settings for every PHP version's FPM workers. |
| **PHP CLI php.ini** | `php.ini` settings for every PHP version's command line, used by deployments, queues and the scheduler. |
| **PHP-FPM pool** | Settings for the `www` pool of every PHP version, such as `pm.*` or `php_admin_value[...]`. |
| **MySQL** | Options for the `[mysqld]` section. Saving restarts MySQL. |
| **PostgreSQL** | `postgresql.conf` settings. Saving restarts PostgreSQL. |
| **Redis** | Redis directives. Saving restarts Redis. |

Only the services the server runs are listed.

## How overrides merge

Your settings are matched against Swage's own by name. A setting you give replaces Swage's, and the editor shows Swage's lines it replaces struck through, so you can see exactly what changes. Anything else is added.

For example, to raise PHP's memory limit for web requests on every site, add this to **PHP-FPM php.ini**:

```ini
memory_limit = 1G
```

## Validation and rollback

When you save, Swage:

1. writes the new config beside the old one,
2. checks it with the service's own validator where it has one, such as `nginx -t` or `php-fpm8.5 -t`,
3. reloads or restarts the service,
4. checks that the service came back healthy.

If any step fails, the previous config is put back and the service is restarted with it. The error is shown on the page, and the full output is on the server's **Activity** page.

## Per-site settings

Some settings are better changed for one site only:

- **PHP settings** such as the memory limit and upload size are on the site's **PHP** page.
- **nginx** directives and `location` blocks for one site are on the site's **nginx** page.

See [Site settings](/docs/site-settings).
