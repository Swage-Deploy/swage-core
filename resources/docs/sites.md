# Sites

A site is one domain served by an app server, deployed from a git repository. Every site runs as a [Linux user of its own](#site-isolation), with its own nginx config, deploy key and PHP-FPM pool.

## Creating a site

Open the server's **Sites** page and choose **New site**:

| Field | Purpose |
|---|---|
| **Domain** | The site's main domain, such as `example.com`. |
| **Aliases** | Extra domains served by the same site, such as `www.example.com`, separated by commas or spaces. A domain or alias another site on the server already uses is refused. |
| **Web directory** | The directory nginx serves, relative to the repository. `/public` for Laravel. |
| **PHP version** | Any version installed on the server. It can be changed later. |
| **Laravel scheduler** | Runs `php artisan schedule:run` every minute. It can be turned off later. |
| **Zero downtime deployments** | Builds each deploy as a new release. See [Deployments](/docs/deployments). This cannot be changed later, and [Octane](/docs/site-settings#laravel-octane) is not available for zero-downtime sites. |
| **System user** | The Linux user the site runs as, such as `shop`, suggested from the domain. Lowercase letters, numbers and underscores, starting with a letter. See [Site isolation](#site-isolation). |
| **Create database** | Creates a MySQL or PostgreSQL database and user for the site and writes them into its `.env`. |

Swage then creates the site's directory and nginx config, writes a starting `.env` and serves a placeholder page until the first deployment.

## Installing a repository

On the site's page, choose where its code comes from:

- **A connected provider**, such as GitHub or GitLab. Pick the account or organization, the repository and the branch from the lists. Only the server's owner can install from a provider, because Swage acts through their connected account. Swage adds a read-only deploy key of the site's own to the repository, so the server can clone it.
- **A custom git repository**, from any git host reachable over SSH. Enter the clone URL, such as `git@git.example.com:team/app.git`, and the branch. Swage generates a deploy key and shows it on the site's page: add it to the repository as a read-only deploy key.

Then press **Deploy now**. See [Source control](/docs/source-control) and [Deployments](/docs/deployments).

## Site isolation

Every site runs as a Linux user of its own, such as `shop`, and lives in `/home/shop/<domain>`, with:

- its own PHP-FPM pool, running as that user,
- its own deploy key,
- daemons, queue workers and scheduled jobs that run as that user,
- on a server with Redis, a **private Redis** server that only it can reach, over a Unix socket.

A site cannot read the files, `.env` or cache of any other site, so sites can belong to different clients or have different levels of trust. nginx only follows a link in a site's files to something the link's owner also owns, so a site cannot serve another site's files by linking to them. Links to its own files, such as Laravel's `public/storage`, work as usual.

## Directory layout

A zero-downtime site looks like this:

```text
/home/example/example.com
├── current -> releases/20261020214502
├── releases
│   ├── 20261019180311
│   └── 20261020214502
└── shared
    ├── .env
    └── storage
```

`current` always points at the live release, and nginx serves `current/<web directory>`. The `.env` file and the `storage` directory are shared by every release.

A site without zero-downtime deployments is a single checkout at `current`, updated in place.

## Domains and SSL

Point each domain and alias at the server's public IP address, then open the site's **SSL** page and obtain a certificate. Certificates come from Let's Encrypt and renew automatically on the server. See [Site settings](/docs/site-settings#ssl-certificates).

## The environment file

Each site starts with an `.env` containing a fresh `APP_KEY`, production settings, its database if you created one, and Redis or database drivers for the cache, queue and sessions. Edit it from the site's **Environment** page. See [Site settings](/docs/site-settings#environment).

## Sharing a site

To give someone just one site without the rest of its server, add them on the site's **Members** tab, as a Manager or a Viewer. See [Server and site members](/docs/members).

## Deleting a site

Deleting a site needs someone who can manage its server, and you type the domain to confirm. It removes its nginx config, PHP-FPM pool, daemons, scheduled jobs, certificate and files from the server, and the deploy key and webhook from its repository. Its databases are kept, so delete those separately if you no longer need them.
