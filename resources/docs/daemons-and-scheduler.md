# Daemons and scheduled jobs

Long-running processes run as **daemons** under Supervisor, and periodic commands run as **scheduled jobs** from cron. Both live on the server and can run as any of its system users.

## Daemons

On the server's **Daemons** page, choose **New daemon** and enter:

| Field | Purpose |
|---|---|
| **Command** | What to run, such as `php8.5 /home/example/example.com/current/artisan reverb:start`. |
| **Directory** | The working directory, such as `/home/example/example.com/current`. |
| **User** | The system user to run as. Use a site's own user for its daemons. Only the server's owner can choose `root`. |
| **Processes** | How many copies to run. |
| **Stop wait** | Seconds to wait for a graceful stop before the process is killed. |

Supervisor starts the daemon right away, restarts it if it exits, and starts it again when the server boots. Each daemon has its own log, shown on the server's **Logs** page.

> **Tip:** Run commands through the `current` path, not a release directory, so the daemon picks up new code when it restarts.

## Queue workers

Queue workers are daemons managed from each site's **Queues & scheduler** page, where you enter the **connection**, the **queues** (comma separated, highest priority first), and the number of **processes**, **timeout**, **sleep** and **tries**.

The default deploy script restarts every queue worker of the site after each release goes live, through the `$RESTART_QUEUES()` macro. See [Deployments](/docs/deployments).

## The Laravel scheduler

Turn on the **Laravel scheduler** when creating a site, or later from its **Queues & scheduler** page. Swage then runs `php artisan schedule:run` every minute as the site's user.

## Laravel Reverb

When a site's live release has `laravel/reverb` installed, its **Queues & scheduler** page shows a **Laravel Reverb** panel. Choose **Enable Reverb** and Swage:

- runs `php artisan reverb:start` as the site's user, on a local port no other site on the server uses, starting at 8080
- sends the site's WebSocket connections (`/app/{key}`) and the API the app broadcasts through (`/apps/…`) to that port through nginx, so Reverb shares the site's domain and certificate
- sets `REVERB_HOST` to the site's domain, `REVERB_PORT` and `REVERB_SCHEME` from the scheme of its `APP_URL` (443 and `https`, or 80 and `http`), and `REVERB_SERVER_HOST` and `REVERB_SERVER_PORT` to the local address in the site's `.env`

Deploy the site after enabling Reverb, so its front end is built with the new `VITE_REVERB_*` values. Each deployment restarts Reverb along with the queue workers, through `$RESTART_QUEUES()`. The `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` that `php artisan reverb:install` added stay as they are.

Swage checks for `laravel/reverb` again after each deployment. Disabling Reverb stops sending traffic to it, then stops it. The site keeps its port, so turning Reverb back on needs no new build.

> **Note:** While Reverb is on, requests to `/app/{one segment}` and anything under `/apps/` go to Reverb instead of the app.

## Scheduled jobs

For anything else, open the server's **Scheduler** page and choose **New job**:

| Field | Purpose |
|---|---|
| **Command** | The command to run, such as `php8.5 /home/example/example.com/current/artisan backup:run`. It may not contain line breaks or `%` characters. |
| **User** | The system user to run as. |
| **Frequency** | Every minute, hourly, nightly, weekly, monthly, or a custom cron expression such as `*/5 * * * *`. |

Each job's output is written to its own log.
