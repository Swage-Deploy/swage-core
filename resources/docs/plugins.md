# Plugins

Plugins extend Swage. Each plugin declares what it extends, which decides where it shows up and where its settings live:

| Kind | Category | What it does | Where it is set up |
|---|---|---|---|
| **Source control** | System | Adds git hosts that sites install repositories from. | The **System** tab of the Plugins page |
| **Server integration** | Server | Adds a service each server can be set up with. | Each server's **Plugins** tab |
| **Site integration** | Site | Connects sites to another service, such as monitoring, and can add steps to their deployments. | Each site's **Plugins** tab |

> **Warning:** A plugin runs inside Swage with access to everything Swage stores, including the SSH keys of every server. Only install plugins you trust.

## Available plugins

| Plugin | Kind | What it adds |
|---|---|---|
| [`swage/ai-review`](https://github.com/Swage-Deploy/swage-plugin-ai-review) | Server integration | Has an AI model of your choice review each server's security, performance and reliability, and suggest what to fix. |
| [`swage/github`](https://github.com/Swage-Deploy/swage-plugin-github) | Source control | GitHub accounts and organizations, with deploy keys and push-to-deploy webhooks. |
| [`swage/gitlab`](https://github.com/Swage-Deploy/swage-plugin-gitlab) | Source control | GitLab.com and self-hosted GitLab, subgroups included. |
| [`swage/laraowl`](https://github.com/Swage-Deploy/swage-plugin-laraowl) | Site integration | Reports requests, exceptions, queries and jobs from your sites to a LaraOwl monitoring server. |
| [`swage/nightwatch`](https://github.com/Swage-Deploy/swage-plugin-nightwatch) | Site integration | Reports your sites to Laravel Nightwatch, with an agent Swage runs and restarts for each site. |
| [`swage/security-scanner`](https://github.com/Swage-Deploy/swage-plugin-security-scanner) | Server integration | Scans each server's SSH, sudo and nginx logs for brute force attempts and probing, with alerts, address blocking and fail2ban. |

### LaraOwl

LaraOwl connects sites to a [LaraOwl](https://github.com/laraowl/laraowl) monitoring server, which collects their requests, exceptions, queries and jobs. Swage only configures the client, so the application needs it first:

```bash
composer require laraowl/client
```

Deploy the site, then open **LaraOwl** on the site's **Plugins** tab. The panel checks the live release for `laraowl/client` and stays disabled until it's there. Enter:

- **Server domain:** your LaraOwl server, such as `owl.example.com`.
- **Project token:** from **Project Settings → API Keys** in LaraOwl. It's stored encrypted, never shown again and redacted from task output. Leave it blank when saving to keep the saved one.
- **Sample rates:** optional. The share of **Requests**, **Commands**, **Exceptions** and **Scheduled tasks** to report, from 0 to 1, such as `0.1` for one in ten. Leave a rate blank to report all of that kind. Sample rates need `swage/laraowl` v1.0.4 or later.

Swage writes these into the site's `.env` as `LARAOWL_SERVER_URL`, `LARAOWL_TOKEN` and `LARAOWL_SAMPLE_REQUESTS`, `LARAOWL_SAMPLE_COMMANDS`, `LARAOWL_SAMPLE_EXCEPTIONS` and `LARAOWL_SAMPLE_TASKS`, then rebuilds the config cache and reloads PHP. **Remove** deletes them all from the `.env`. To send the application's logs to LaraOwl too, add `laraowl` to `LOG_STACK` in the site's [environment](/docs/site-settings#environment), such as `LOG_STACK=stack,laraowl`.

### Security scanner

The Security scanner reads each server's SSH, sudo and nginx access logs every few minutes over SSH, with no agent installed. Turn it on for a server from the server's **Plugins** tab. It then:

- counts failed SSH logins, failed sudo attempts and probes for files such as `/.env` or `/wp-login.php`, and shows the busiest addresses,
- raises an alert when someone logs in over SSH from a new address, or from one that was failing to log in,
- blocks an address you choose in the firewall and in nginx, so visitors behind Cloudflare are blocked too,
- can install fail2ban, which bans addresses behind repeated SSH failures and probing, but never the address Swage connects from,
- lets you trust addresses or ranges, such as your office's, which are never counted, alerted on, blocked or banned by fail2ban, and which the [AI review](#ai-review) leaves out.

Only counts are stored, never whole log lines. With Swage v1.3.3 or later, a server with open SSH login alerts gets a badge on its card on the servers page and on its **Plugins** tab. With Swage v1.3.16 or later, each new alert is also a [notification](/docs/notifications) to the server's owner and Managers. See the [plugin's README](https://github.com/Swage-Deploy/swage-plugin-security-scanner#readme) for what it looks for and its settings.

Earlier versions of the scanner included the AI review. It's now a plugin of its own, below. Updating the scanner removes its old reviews and API settings, so install the AI review plugin and set it up again.

### AI review

The AI review has an AI model look over each server and suggest what to fix in its security, its performance and capacity, and its reliability. It needs Swage v1.3.3 or later, and works with any OpenAI compatible chat completions API, such as OpenAI, OpenRouter, Ollama Cloud, or your own Ollama or vLLM.

**It only reads and suggests.** The model gets no tools and no access to your servers. Swage gathers what it reviews, sends it as text, and stores the findings that come back in its database. Nothing the model suggests is ever run: a person decides what to do.

To set it up:

1. Install **AI review** from the **Available** list on the **Plugins** page.
2. On the **Server** tab of the Plugins page, click **Configure AI review**. Enter the **API URL**, the base URL ending before `/chat/completions`, such as `https://api.openai.com/v1` or `https://ollama.com/v1`. Then enter the **API key**, which is stored encrypted and can be left blank for a local Ollama, and the **Model**. The model needs to return JSON reliably: Ollama Cloud doesn't enforce the response schema, so pick one that follows instructions well, such as `gpt-oss:120b`.
3. On each server's **Plugins** tab, open **AI review** and turn on **Review this server with AI**. The first review runs straight away. After that, each server is reviewed every 6 hours, or whenever someone clicks **Review now**.

Each review sends the API:

- **What Swage knows about the server:** its CPU, memory and disk, what runs on it, how Swage tuned PHP-FPM, MySQL, PostgreSQL and Redis for that hardware, and any settings you changed on its **Config** page.
- **A snapshot of the server**, read by a fixed script over Swage's SSH connection that only runs commands that read:
  - **Setup:** the OS and kernel, whether a reboot is needed, pending updates, whether automatic updates are on, the effective SSH settings, listening ports, the firewall rules, accounts that can log in, which accounts have a password (only their names, never the password hashes), how many authorized SSH keys each has, passwordless sudo rules and recent logins.
  - **Resources:** whether the server is a virtual machine or a container such as LXC, with any memory and CPU limits the container has, load, memory and swap, disk space and inodes, which processes use the most memory, and out of memory kills in the last week.
  - **Services:** each PHP version's pools, `php.ini` limits and how much memory its workers really use, nginx's worker settings, MySQL's or PostgreSQL's settings and figures such as the most connections used, and Redis's memory, evictions and refused connections.
  - **Logs:** summaries of the PHP-FPM, nginx error, MySQL, PostgreSQL and Redis logs and the last day of system journal errors, with repeated messages counted once, such as `server reached pm.max_children`. Supervisor programs that aren't running and failed services are included too.
- **What the [Security scanner](#security-scanner) recorded**, when it scans the server: event totals, the busiest hostile addresses with a few of the usernames or paths each tried, open alerts, blocked addresses and fail2ban's state.

That includes IP addresses, account names and log messages, so only use an API you trust with them, or run the model yourself. Sites' own Laravel logs are never read.

Swage works out some figures itself rather than leaving the arithmetic to the model, such as how many PHP workers fit in the memory set aside for PHP at the size they really are. Suggestions are based on the server's hardware, such as raising `pm.max_children` only as far as its memory allows, or saying when the server needs more memory instead. On a container, kernel settings and swap belong to the machine it runs on, so the review doesn't suggest changing them. When a security issue is one the Security scanner handles, such as SSH brute force or probing for files like `.env`, the review suggests installing it, or turning it on for the server.

What the review can't do:

- **Change anything.** It can't run commands, edit files, block addresses or restart services, on your servers or on Swage's host. The only thing it writes is the review, to Swage's database.
- **See everything.** It only sees the data above. It doesn't read site code, `.env` files, databases or whole log lines, so it won't find a vulnerable app or a leaked secret.
- **Be always right.** Its findings come from a model and can be wrong, or miss things. Check a suggestion before acting on it, and don't run a command you don't understand. Usernames, paths and log messages can be chosen by attackers, and the model is told to treat everything it's given as data, not instructions.

Each review in the panel shows a short summary, then each finding with its severity, whether it's about security, performance or reliability, what was seen and a suggestion. To confirm a review that found nothing really read the server, it also shows:

- **Checked:** one line for each part of the server it looked at, with what it saw, such as "PHP-FPM: workers average 82 MB, pm.max_children reached 3 times in 2 days".
- **The model and tokens:** the model the API reports it used, and how many tokens it read and wrote.
- **Parts that couldn't be read:** any part of the snapshot whose command failed, since anything wrong there wasn't checked. Services the server doesn't run aren't counted.
- **What was sent:** the exact data the model was given. Only people who can manage the server see this.

To stop seeing a finding you've decided to accept, such as SSH on port 22, click **Ignore** on it. It moves to an **Ignored** section that says whether the latest review still reports it, and **Stop ignoring** brings it back. Reviews still check for ignored findings and still report them: they're only listed apart from the findings to act on.

A server whose latest review has findings nobody has ignored gets a badge on its card on the servers page and on its **Plugins** tab, with how many there are. The badge is red when any of them is high severity, amber for medium and grey for low. Findings only count while the server is still reviewed.

To test a model before trusting it, set up something it should flag on a test server, such as `PermitRootLogin yes` or a service listening on a public port, and click **Review now**.

A failed review shows its error in the panel and is tried again when the next review is due. Reviews are kept for 30 days. The [plugin's README](https://github.com/Swage-Deploy/swage-plugin-ai-review#readme) lists the settings for how often reviews run and how long they're kept.

## Official plugins

The official plugins are listed in the **plugin index**, a JSON file at [Swage-Deploy/plugin-repo](https://github.com/Swage-Deploy/plugin-repo). Swage installs and updates each one only from the repository the index lists for it, and only from **version tags signed with a Swage release key** (see [Signed releases](/docs/updating#signed-releases)). A tag without a good signature is refused, so taking over a plugin's repository is not enough to ship code to your panel.

Other plugins can still be [installed from their repository](#installing-from-a-repository).

To use a list of your own instead, set `SWAGE_PLUGIN_INDEX` to its URL. Plugins in it count as official, so their tags must be signed with a key in Swage's release signers file too. Install plugins that are not signed that way from their repository instead. The file looks like this:

```json
{
    "plugins": [
        {
            "name": "swage/gitlab",
            "title": "GitLab",
            "description": "Install repositories from GitLab.com or a self-hosted GitLab.",
            "kind": "source-control",
            "repository": "https://github.com/Swage-Deploy/swage-plugin-gitlab.git",
            "homepage": "https://github.com/Swage-Deploy/swage-plugin-gitlab"
        }
    ]
}
```

| Field | Purpose |
|---|---|
| `name` | The plugin's Composer package name. |
| `title` | The name shown in the panel. |
| `description` | A sentence shown on the **Available** list. |
| `kind` | What the plugin extends: `source-control`, `server-integration` or `site-integration`. Entries with any other kind are ignored. |
| `repository` | The git URL Swage installs the plugin from. |
| `homepage` | Optional. An `https://` link shown beside the plugin. |

## Managing plugins

Administrators manage plugins on the **Plugins** page, after confirming their password. The page has a tab for each category, **System**, **Server** and **Site**, each listing its installed plugins and the ones available to install. The **Activity** tab lists recent installs, updates and removals, with the output of each rebuild.

### Installing

Choose **Install** beside a plugin in the **Available** list. Swage installs the plugin's newest signed version tag.

### Installing from a repository

> **Note:** This is turned off on panels installed with `--disable-unofficial-plugins`, such as [Swage Cloud](/docs/swage-cloud) panels, and when `SWAGE_UNOFFICIAL_PLUGINS=false`. Only plugins in the plugin index can be installed there.

To install a plugin that is not in the plugin index, such as your own or one shared on GitHub, choose **Install from a repository** at the top of the **Plugins** page:

1. Enter its git URL, such as `https://github.com/acme/swage-gitea`, or `git@github.com:acme/swage-gitea.git` for a private repository the panel host can reach. Leave **Tag or branch** empty for its newest version tag, or its default branch when it has none.
2. Choose **Check repository**. Swage shows the version and commit it found.
3. Choose **Install**. Swage installs that commit, and refuses if the repository has moved on since the check.

> **Warning:** Swage has not reviewed a plugin installed this way and does not check its signature. It runs with the same access as any other plugin, so only install plugins from people you trust.

These plugins are marked **Unofficial** on the Plugins page. A plugin cannot be installed this way under the name of an official plugin, nor replace a plugin of its name installed from another repository. Remove that one first.

### Updating

Updates are checked whenever the page opens, at most once an hour per plugin, or straight away with **Check again**.

Plugins are updated through **version tags** such as `v1.0.1`. The newest tag is offered whenever it is newer than the installed version, and a plugin installed from a branch moves over to tags at its next tagged release. Only a repository with no version tags offers new commits on its branch instead.

**Update** installs one update, and **Update all** installs every available update for the plugins on that tab in a single rebuild. Panels with [automatic updates](/docs/updating#automatic-updates), such as [Swage Cloud](/docs/swage-cloud) panels, have neither button: their plugins update every day, right after Swage.

- **Official plugins** are only offered updates while the index still lists them, from the same repository, and only signed version tags are installed. Once Swage has a release key, they no longer follow a branch.
- **Unofficial plugins** are updated from the repository they were installed from, without checking a signature.

### Removing

**Remove** takes a plugin out and rebuilds Swage without it. Its settings stay in the database and are picked up again if you reinstall it.

- For a **source control** plugin, sites using its provider keep deploying from their clone URL, but their repositories cannot be reinstalled and quick deploy cannot be changed until the plugin is back.
- For an **integration**, nothing it set up on servers or sites is changed or removed.

## How changes are applied

Each change rebuilds the panel in the background, the same way an [update](/docs/updating) does, and the page shows its progress. If the new release fails to build or fails its health check, the change is undone and the previous release keeps running.

A plugin's code only changes when you update it: updating Swage rebuilds every plugin at the commit it is at. Plugin changes also appear in the **Updates** history.

## Setting up plugins

- **Source control plugins** are configured on the **System** tab. Each provider's **Configure** page holds the settings it declares, such as OAuth credentials, and shows the callback URL to give the provider. See [Source control](/docs/source-control).
- **Server plugins**, such as the Security scanner, are set up for each server on its **Plugins** tab. Plugins with settings that apply to every server, such as the AI review, list a **Configure** page on the **Server** tab of the Plugins page.
- **Site plugins**, such as LaraOwl and Nightwatch, are set up for each site on its **Plugins** tab. Any settings that apply to every site are on a **Configure** page on the **Site** tab of the Plugins page.

## From the command line

The same can be done on the panel host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:plugin:install swage/gitlab
sudo -u swage php /home/swage/app/current/artisan swage:plugin:install --url=https://github.com/acme/swage-gitea
sudo -u swage php /home/swage/app/current/artisan swage:plugin:update
sudo -u swage php /home/swage/app/current/artisan swage:plugin:list
sudo -u swage php /home/swage/app/current/artisan swage:plugin:remove swage/gitlab
```
