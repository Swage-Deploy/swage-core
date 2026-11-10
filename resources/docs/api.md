# API

Swage has a JSON API for scripts, CI pipelines and other tools. It covers servers and sites: connecting servers, creating sites, deploying, the environment, certificates, Artisan commands, daemons, queue workers, scheduled jobs and databases. Users, plugins and updates are only managed in the panel.

## API keys

Create keys under **Settings → API keys**. Give each key a name, an expiry (30, 90 or 365 days, or never) and the areas it may use:

| Area | Read | Write |
|---|---|---|
| **Servers** | List servers and their activity. | Connect, reboot, re-optimize and delete servers. |
| **Sites** | List sites and their settings. | Create, change and delete sites, and install their repositories. |
| **Deployments** | List deployments, their output and deploy scripts. | Deploy, change deploy scripts and turn quick deploy on or off. |
| **Environment** | Read sites' `.env` files, which hold their secrets. | Replace sites' `.env` files. |
| **Certificates** | See sites' SSL certificates. | Obtain and remove certificates. |
| **Commands** | List Artisan commands run on sites and their output. | Run Artisan commands on sites. |
| **Daemons** | List daemons, queue workers and scheduled jobs. | Create, restart and remove them, and turn sites' schedulers on or off. |
| **Databases** | List databases and database users. | Create, change and delete them. |

Giving an area write access also gives it read.

**Run commands as root** is a separate checkbox, which **Full access** does not tick. Without it, a key cannot create a daemon or scheduled job that runs as `root`, since one controls the whole server. Even with it, only the server's owner can. It needs write access to **Daemons**. Write access to **Deployments** or **Commands** still runs code on the server as the site's user, so give those only to keys you would trust with the site.

The key is shown **once**, when you create it. Store it in your CI's secrets or a password manager. Swage keeps only a hash of it.

The keys page lists each key with what it can do, when it was last used and when it expires. **Revoke** stops a key working straight away. Keys also stop working when they expire or their user is removed.

> **Note:** A key acts as you. It reaches only the servers and sites you can, with the same [role](/docs/users#who-can-do-what) you have on each, so a key of a Viewer can read but never change anything. The areas only narrow that further.

An administrator signed in as someone else cannot see or create that user's keys.

To let an AI assistant read your panel, create a key with **AI assistant** and connect it to Swage's read-only MCP server. See [AI assistants](/docs/ai-assistants).

## Making requests

Send the key as a bearer token, and ask for JSON:

```bash
curl https://swage.example.com/api/v1/servers \
    -H "Authorization: Bearer $SWAGE_API_KEY" \
    -H "Accept: application/json"
```

Send request bodies as JSON with `Content-Type: application/json`. Every endpoint is under `/api/v1`. Each key's user can make 120 requests a minute.

Responses wrap their result in `data`. Lists of deployments, commands and activity are paginated 50 at a time, with `links` and `meta` beside `data`. Pass `?page=2` for the next page.

Work on servers happens in the background, the same as in the panel. Requests that start it return `202 Accepted` straight away. Poll the resource's `status`, such as a deployment's, to follow it.

### Errors

| Status | Meaning |
|---|---|
| `401` | The key is missing, wrong, revoked or expired. |
| `403` | The key lacks the area's ability, or you cannot do this on this server or site. |
| `404` | The server or site does not exist, or the site is not on that server. |
| `409` | The server or site is not ready, such as deploying a site with no repository. |
| `422` | The input is invalid. `errors` lists the message for each field, the same messages the panel shows. |
| `429` | Too many requests. Wait for the time in the `Retry-After` header. |
| `502` | Swage could not reach the server or the source control provider. `message` says why. |

## Endpoints

### Your key

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/user` | | Shows the key's user and its abilities. |

### Servers

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers` | `servers:read` | Lists the servers you can open. |
| `POST` | `/servers` | `servers:write` | Connects a server. |
| `GET` | `/servers/{server}` | `servers:read` | Shows a server. |
| `DELETE` | `/servers/{server}` | `servers:write` | Stops managing the server. Only its owner can. |
| `POST` | `/servers/{server}/reboot` | `servers:write` | Reboots the server. |
| `POST` | `/servers/{server}/optimize` | `servers:write` | Re-detects the hardware and re-tunes every service. |
| `POST` | `/servers/{server}/php-versions` | `servers:write` | Installs the PHP `version` it sends, such as `8.4`, beside the server's others. |
| `POST` | `/servers/{server}/php-versions/{version}/default` | `servers:write` | Makes an installed PHP version the default. |
| `DELETE` | `/servers/{server}/php-versions/{version}` | `servers:write` | Removes a PHP version that no site, daemon or scheduled job runs. |
| `GET` | `/servers/{server}/activity` | `servers:read` | Lists the scripts Swage has run on the server. |
| `GET` | `/servers/{server}/activity/{task}` | `servers:read` | Shows one, with its output. |

To connect a server, send its `name` and `ip_address`. Optionally send `type` (`app` or `database`), `private_ip_address`, `ssh_port`, `php_versions` (such as `["8.4", "8.5"]`), `default_php_version`, `database` (`mysql`, `postgres`, `postgres-pgvector` or `none`) and `has_redis`. The response's `provision_command` is the command to run as root on the server, as on its page in the panel. It starts provisioning for 24 hours. After that, fetch the server again for a new one.

While a PHP version is being installed, removed or made the default, the server's `php_task` holds the `action` (`install`, `default` or `remove`) and the `version`. Otherwise it is `null`.

```bash
curl -X POST https://swage.example.com/api/v1/servers \
    -H "Authorization: Bearer $SWAGE_API_KEY" \
    -H "Content-Type: application/json" \
    -d '{"name": "production-1", "ip_address": "203.0.113.10"}'
```

### Sites

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/sites` | `sites:read` | Lists every site you can open, including sites shared with you on their own. |
| `GET` | `/servers/{server}/sites` | `sites:read` | Lists the server's sites. |
| `POST` | `/servers/{server}/sites` | `sites:write` | Creates a site. |
| `GET` | `/servers/{server}/sites/{site}` | `sites:read` | Shows a site. |
| `PATCH` | `/servers/{server}/sites/{site}` | `sites:write` | Changes its `aliases`, `web_directory` or `php_version`. |
| `DELETE` | `/servers/{server}/sites/{site}` | `sites:write` | Deletes the site. Its databases are kept. |
| `PUT` | `/servers/{server}/sites/{site}/repository` | `sites:write` | Installs a repository. |

To create a site, send its `domain`. Optionally send `aliases` (a list, or a string separated by commas), `web_directory` (`/public`), `php_version` (the server's default), `zero_downtime` (`true`), `scheduler` (`true`), `system_user` (suggested from the domain, since every site runs as its own user), and `create_database` with `database` and `database_engine`.

To install a repository, send `repository` and `branch`. Without a `source` it is a [custom git repository](/docs/source-control#custom-git-repositories), with a clone URL such as `git@git.example.com:team/app.git`, and the site's `repository.deploy_key` is the key to add to it. Installing from a provider, with a `source` such as `github` and a repository such as `acme/app`, is for the server's owner only, as in the panel.

### Deployments

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/sites/{site}/deployments` | `deployments:read` | Lists the site's deployments. |
| `POST` | `/servers/{server}/sites/{site}/deployments` | `deployments:write` | Deploys the site. |
| `GET` | `/servers/{server}/sites/{site}/deployments/{deployment}` | `deployments:read` | Shows a deployment, with its output. |
| `GET` | `/servers/{server}/sites/{site}/deploy-script` | `deployments:read` | Shows the deploy script. |
| `PUT` | `/servers/{server}/sites/{site}/deploy-script` | `deployments:write` | Replaces it with `deploy_script`. |
| `PUT` | `/servers/{server}/sites/{site}/quick-deploy` | `deployments:write` | Turns quick deploy on or off with `enabled`. Only the server's owner can. |

A deployment's `status` is `pending`, `running`, `finished` or `failed`. To deploy from CI without an API key, the site's [deploy trigger URL](/docs/deployments#triggering-a-deployment) still works.

### Environment

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/sites/{site}/environment` | `environment:read` | Reads the site's `.env` file from the server. |
| `PUT` | `/servers/{server}/sites/{site}/environment` | `environment:write` | Replaces it with `environment`, and clears the config cache unless `clear_config` is `false`. |

Only the server's owner and Managers can read or write a site's environment, whatever the key's abilities.

### Certificates

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/sites/{site}/certificate` | `certificates:read` | Shows the site's certificate, or `null`. |
| `POST` | `/servers/{server}/sites/{site}/certificate` | `certificates:write` | Requests one for the site's domain and aliases, or the `domains` given. |
| `DELETE` | `/servers/{server}/sites/{site}/certificate` | `certificates:write` | Removes it. |

### Commands

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/sites/{site}/commands` | `commands:read` | Lists the Artisan commands run on the site. |
| `POST` | `/servers/{server}/sites/{site}/commands` | `commands:write` | Runs `command`, such as `migrate --force`. |
| `GET` | `/servers/{server}/sites/{site}/commands/{command}` | `commands:read` | Shows one, with its output. |

Commands are refused the same way as on the [Commands tab](/docs/site-settings#commands), and only the server's owner and Managers can see or run them.

### Daemons, queue workers and scheduled jobs

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/daemons` | `daemons:read` | Lists the server's daemons, including queue workers. |
| `POST` | `/servers/{server}/daemons` | `daemons:write` | Creates one from `command`, and optionally `directory`, `user`, `processes` and `stop_wait_seconds`. A `user` of `root` also needs `root`. |
| `POST` | `/servers/{server}/daemons/{daemon}/restart` | `daemons:write` | Restarts it. |
| `DELETE` | `/servers/{server}/daemons/{daemon}` | `daemons:write` | Removes it. |
| `GET` | `/servers/{server}/scheduled-jobs` | `daemons:read` | Lists the server's scheduled jobs. |
| `POST` | `/servers/{server}/scheduled-jobs` | `daemons:write` | Creates one from `command`, `user` and `frequency`, a cron expression such as `0 * * * *`. A `user` of `root` also needs `root`. |
| `DELETE` | `/servers/{server}/scheduled-jobs/{scheduledJob}` | `daemons:write` | Removes it. |
| `GET` | `/servers/{server}/sites/{site}/workers` | `daemons:read` | Lists the site's queue workers. |
| `POST` | `/servers/{server}/sites/{site}/workers` | `daemons:write` | Starts one. Optionally send `connection`, `queue`, `processes`, `timeout`, `sleep` and `tries`. |
| `POST` | `/servers/{server}/sites/{site}/workers/{daemon}/restart` | `daemons:write` | Restarts it. |
| `DELETE` | `/servers/{server}/sites/{site}/workers/{daemon}` | `daemons:write` | Removes it. |
| `GET` | `/servers/{server}/sites/{site}/scheduler` | `daemons:read` | Shows whether the Laravel scheduler is on. |
| `PUT` | `/servers/{server}/sites/{site}/scheduler` | `daemons:write` | Turns it on or off with `enabled`. |

### Databases

| Method | Path | Ability | Does |
|---|---|---|---|
| `GET` | `/servers/{server}/databases` | `databases:read` | Lists the server's databases. |
| `POST` | `/servers/{server}/databases` | `databases:write` | Creates one from `name`, and optionally `engine`. |
| `DELETE` | `/servers/{server}/databases/{database}` | `databases:write` | Drops it and all of its data. |
| `GET` | `/servers/{server}/database-users` | `databases:read` | Lists the server's database users. |
| `POST` | `/servers/{server}/database-users` | `databases:write` | Creates one from `username`, `password`, and optionally `engine` and `databases`, a list of database IDs. |
| `PATCH` | `/servers/{server}/database-users/{databaseUser}` | `databases:write` | Changes its `password` or `databases`. |
| `DELETE` | `/servers/{server}/database-users/{databaseUser}` | `databases:write` | Deletes it. |

Database users' passwords are never returned.

## Deploying from CI

A key with only **Deployments: write** can deploy and nothing else. For example, in GitHub Actions, with the key stored as the `SWAGE_API_KEY` secret:

```yaml
- name: Deploy
  run: |
    curl --fail-with-body -X POST \
      -H "Authorization: Bearer ${{ secrets.SWAGE_API_KEY }}" \
      -H "Accept: application/json" \
      https://swage.example.com/api/v1/servers/1/sites/4/deployments
```

The server and site IDs are in the panel's address bar on the site's page, and in `GET /sites`.
