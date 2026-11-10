# Deployments

A deployment runs the site's **deploy script** on the server as the site's user. The script is plain bash, and Swage provides macros and variables for the parts it manages.

## Triggering a deployment

There are three ways to deploy:

- **Deploy now** on the site's page.
- **Quick deploy**: turn it on from the site's page, and every push to the site's branch deploys. Swage creates a webhook on the repository through its provider, with a secret of its own to verify each delivery. Quick deploy needs a provider plugin such as GitHub or GitLab, and only the server's owner can turn it on or off.
- **The deploy trigger URL**, shown on the site's page. Send it a `POST` request from CI, a custom git host, or anywhere else.

```bash
curl -X POST https://swage.example.com/deploy/12/<token>
```

To keep the token out of access logs, send it as a bearer token instead:

```bash
curl -X POST -H "Authorization: Bearer <token>" https://swage.example.com/deploy/12
```

The response is `202 Accepted` with the queued deployment's ID. An unknown site or wrong token returns `404`, and a site with no repository installed yet returns `409`.

Deployments of the same site run one at a time, in the order they were triggered.

## Zero-downtime deployments

With zero-downtime deployments, each deploy:

1. clones the branch into a new directory under `releases/`, named after the time,
2. links the shared `storage` directory and `.env` into it,
3. runs your build steps in it, such as `composer install` and `npm run build`, while the old release keeps serving traffic,
4. points `current` at the new release in a single atomic rename,
5. reloads PHP-FPM, so OPcache picks up the new code,
6. deletes old releases, keeping the newest four.

If any step before the switch fails, the deployment stops, the half-built release is deleted, and the live release is never touched.

Change how many releases are kept with `SWAGE_RELEASES_TO_KEEP`. See [Configuration](/docs/configuration).

## In-place deployments

Without zero-downtime deployments, the site is a single checkout. Each deploy fetches the branch and resets the checkout to it, runs the build steps, then reloads PHP-FPM, or Octane's workers for an [Octane](/docs/site-settings#laravel-octane) site. Requests during the deploy may see a half-updated site, so use this for sites that need it, such as Octane sites.

## The deploy script

New sites get this script, which you can change from the site's page:

```bash
$CREATE_RELEASE()

cd $SWAGE_RELEASE_DIRECTORY

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package.json ]; then
    npm ci
    npm run build
fi

php artisan migrate --force
php artisan cache:clear
php artisan optimize
php artisan storage:link

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

The script runs with `set -Eeuo pipefail`, so any failing command fails the deployment. `php` and Composer use the site's PHP version.

### Macros

| Macro | What it does |
|---|---|
| `$CREATE_RELEASE()` | Clones the branch into a new release and links `.env` and `storage` into it. For an in-place site, fetches and resets the checkout instead. Also records the commit being deployed. |
| `$ACTIVATE_RELEASE()` | Points `current` at the new release, reloads PHP-FPM and deletes old releases. For an in-place site, reloads PHP-FPM or Octane's workers. |
| `$RESTART_QUEUES()` | Restarts every queue worker of the site. |

### Variables

| Variable | Value |
|---|---|
| `SWAGE_SITE_PATH` | The site's directory, such as `/home/example/example.com`. |
| `SWAGE_SITE_BRANCH` | The branch being deployed. |
| `SWAGE_RELEASE_NAME` | The new release's name, such as `20261020214502`. Zero-downtime sites only. |
| `SWAGE_RELEASE_DIRECTORY` | Where the code being deployed is: the new release, or `current` for an in-place site. |
| `SWAGE_COMMIT_HASH` | The full hash of the commit being deployed. Set by `$CREATE_RELEASE()`. |
| `SWAGE_PHP` | The site's PHP binary, such as `php8.5`. |
| `SWAGE_PHP_FPM` | The site's PHP-FPM service, such as `php8.5-fpm`. |

### Helpers

Sites cannot use `sudo` freely. Two helpers do what a deploy script needs:

| Helper | What it does |
|---|---|
| `swage_reload_php` | Reloads the site's PHP-FPM version. |
| `swage_restart_daemon <id>` | Restarts one of the site's own daemons or queue workers. |

### Steps added by plugins

[Site plugins](/docs/plugins) can add their own steps right after `$CREATE_RELEASE()` and `$ACTIVATE_RELEASE()`, such as telling a monitoring service about the new release. Steps are only added where your script uses those macros, so keep them in a custom script.

## Deployment history

The site's page lists recent deployments with their status, the commit and its author, and who or what triggered them. Each commit links to its page on the git host. Open one to read the full output of its deploy script.
