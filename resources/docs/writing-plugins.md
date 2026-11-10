# Writing plugins

A plugin is a Composer package that Swage installs into the panel. This page walks through writing a source control plugin, which adds a git host such as Gitea, then [server and site integrations](#server-and-site-integrations). The [GitLab plugin](https://github.com/Swage-Deploy/swage-plugin-gitlab) and the [LaraOwl plugin](https://github.com/Swage-Deploy/swage-plugin-laraowl) are complete examples to read alongside it.

## The package

The plugin's `composer.json` declares that it is a Swage plugin, what it extends, the version of the contract it implements, and its service provider:

```json
{
    "name": "acme/swage-gitea",
    "description": "Gitea source control for Swage.",
    "type": "swage-plugin",
    "license": "MIT",
    "require": {
        "php": "^8.3"
    },
    "autoload": {
        "psr-4": {
            "Acme\\SwageGitea\\": "src/"
        }
    },
    "extra": {
        "swage": {
            "kind": "source-control",
            "contract": 1
        },
        "laravel": {
            "providers": [
                "Acme\\SwageGitea\\GiteaServiceProvider"
            ]
        }
    }
}
```

| Key | Value |
|---|---|
| `type` | Must be `swage-plugin`. |
| `extra.swage.kind` | What the plugin extends: `source-control`, `server-integration` or `site-integration`. |
| `extra.swage.contract` | The version of the contract the plugin implements, currently `1`. Swage refuses to install a plugin written for a different version. |
| `extra.laravel.providers` | The service provider that registers the plugin. Swage refuses a plugin without one, since it would never be loaded. |

## Registering the provider

Register the provider class from the service provider's `boot` method:

```php
namespace Acme\SwageGitea;

use App\SourceControl\SourceControlProviders;
use Illuminate\Support\ServiceProvider;

class GiteaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SourceControlProviders::class)->register(GiteaProvider::class);
    }
}
```

## The provider

The provider implements `App\SourceControl\SourceControlProvider`. It connects accounts and verifies webhook deliveries.

| Method | What it must do |
|---|---|
| `id(): string` | The short identifier stored on sites and used in URLs, such as `gitea`. Lowercase letters, numbers and dashes. |
| `name(): string` | The name shown to users, such as `Gitea`. |
| `isConfigured(): bool` | Whether the provider has the settings it needs to connect accounts, such as OAuth credentials. |
| `setupInstructions(): string` | How to configure the provider, shown while it is not configured. |
| `redirect(): RedirectResponse` | Send the user to the provider to authorize access to their repositories. |
| `connectedAccount(Request $request): ConnectedAccount` | Finish connecting an account when the provider sends the user back. Throw `SourceControlException` if authorization failed or was denied. |
| `client(SourceControlConnection $connection): SourceControlClient` | Create an API client acting as a connected account. Providers whose tokens expire refresh them here and save the new ones to the connection. |
| `push(Request $request, string $secret): ?Push` | Read a webhook delivery after checking it came from the provider using the secret Swage gave the webhook. Return `null` for deliveries that are not pushes, such as test pings. Throw `InvalidWebhookException` if it cannot be verified. |

`ConnectedAccount` holds the account's `id`, `username`, `token`, and optionally a `refreshToken` and `expiresAt`. `Push` holds the pushed `branch` and optionally the `pusher`.

### Linking to commits

Deployments link to the commit they deployed. By default the link is built from the clone URL, which suits most git hosts. If yours uses a different address for commits, also implement `App\SourceControl\LinksToCommits` on the provider:

| Method | What it must do |
|---|---|
| `commitUrl(string $repository, string $commit): string` | The web page of a commit, such as `https://gitea.example.com/acme/app/commit/{commit}`. |

### URLs

| URL | Purpose |
|---|---|
| `https://your-domain/auth/{id}/callback` | Where the provider sends users back after authorizing. Build it with `route('source-control.callback', $this->id())`. |
| `https://your-domain/webhooks/{id}/{site}` | Where webhook deliveries arrive. Swage passes the URL to `createWebhook`. |

## The client

The client implements `App\SourceControl\SourceControlClient`, the repository operations Swage performs as a connected account. Repositories are identified by their full path on the provider, such as `acme/app` or `group/subgroup/app`.

| Method | What it must do |
|---|---|
| `username(): string` | The connected account's username. |
| `owners(): array` | The accounts, organizations or groups repositories can be installed from, the user's own account first. |
| `repositories(string $owner): array` | Full paths of an owner's repositories the user may add deploy keys and webhooks to. |
| `defaultBranch(string $repository): string` | The repository's default branch. |
| `cloneUrl(string $repository): string` | The SSH URL servers clone the repository from. |
| `addDeployKey(string $repository, string $title, string $publicKey): string` | Add a **read-only** deploy key and return its ID. |
| `deleteDeployKey(string $repository, string $keyId): void` | Remove a deploy key. A key that no longer exists is ignored. |
| `createWebhook(string $repository, string $url, string $secret): string` | Create a webhook delivering pushes to the URL, signed or authenticated with the secret, and return its ID. |
| `deleteWebhook(string $repository, string $webhookId): void` | Remove a webhook. A webhook that no longer exists is ignored. |

Every method throws `SourceControlException`, with a message that can be shown to the user, when the provider refuses a request.

## Settings

To let administrators enter settings such as OAuth credentials on the provider's config page, implement `App\SourceControl\HasSettings` on the provider and return `App\SourceControl\Setting`s:

```php
use App\SourceControl\HasSettings;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Setting;

class GiteaProvider implements SourceControlProvider, HasSettings
{
    public function settings(): array
    {
        return [
            new Setting('url', 'Gitea URL', type: Setting::URL, placeholder: 'https://gitea.example.com'),
            new Setting('client_id', 'Client ID'),
            new Setting('client_secret', 'Client secret', type: Setting::SECRET),
        ];
    }

    public function isConfigured(): bool
    {
        $settings = app(ProviderSettings::class);

        return filled($settings->get($this->id(), 'client_id'))
            && filled($settings->get($this->id(), 'client_secret'));
    }

    // ...
}
```

| `Setting` argument | Purpose |
|---|---|
| `key` | Lowercase letters, numbers and underscores, unique within the provider. |
| `label` | The field's label. |
| `description` | Optional help shown under the field. |
| `type` | `Setting::TEXT`, `Setting::URL`, or `Setting::SECRET` for values stored encrypted and never shown again. |
| `required` | Whether the provider cannot be used until the setting has a value. Defaults to `true`. |
| `placeholder` | Optional placeholder text. |

Read values with `app(ProviderSettings::class)->get($this->id(), 'client_id')`.

## Server and site integrations

Integrations add services to servers or sites instead of git hosts. Set `extra.swage.kind` to `server-integration` or `site-integration`, and register the integration from the service provider's `boot` method:

```php
use App\Servers\ServerIntegrations;
use App\Sites\SiteIntegrations;

$this->app->make(ServerIntegrations::class)->register(NetdataIntegration::class);
$this->app->make(SiteIntegrations::class)->register(MonitoringIntegration::class);
```

### Server integrations

A server integration implements `App\Servers\ServerIntegration`:

| Method | What it must do |
|---|---|
| `id(): string` | A short identifier, such as `netdata`. Lowercase letters, numbers and dashes. |
| `settingsComponent(): string` | The name of a Livewire component shown on each server's **Plugins** tab. It is given the server as `server`. |
| `secrets(Server $server): array` | The secrets the integration stores for the server, so Swage can mask them in task and deployment output. |

### Site integrations

A site integration implements `App\Sites\SiteIntegration`:

| Method | What it must do |
|---|---|
| `id(): string` | A short identifier, such as `laraowl`. Lowercase letters, numbers and dashes. |
| `settingsComponent(): string` | The name of a Livewire component shown on each site's **Plugins** tab. It is given the site as `site`. |
| `secrets(Server $server): array` | The secrets the integration stores for the server's sites, so Swage can mask them in task and deployment output. |
| `deployed(Site $site): void` | Called once each deployment of the site finishes, whether or not it succeeded. |

### Naming the tab

The **Plugins** tab gives each server or site integration a tab of its own, and shows one at a time. The tab is named after the integration's `id`, such as "Security Scanner" for `security-scanner`. To choose its name and icon, also implement `App\Plugins\HasPluginTab`, which needs Swage v1.0.1 or later:

| Method | What it must do |
|---|---|
| `tabLabel(): string` | The tab's name, such as `Security scanner`. |
| `tabIcon(): string` | The name of a [Heroicon](https://heroicons.com), such as `shield-check`. |

```php
use App\Plugins\HasPluginTab;

class SecurityScannerIntegration implements ServerIntegration, HasPluginTab
{
    public function tabLabel(): string
    {
        return __('Security scanner');
    }

    public function tabIcon(): string
    {
        return 'shield-check';
    }

    // ...
}
```

### Settings for every server or site

To let administrators enter settings that apply to every server or site the integration is used on, such as an API key, implement `App\Plugins\HasPluginSettings`, which needs Swage v1.3.0 or later. The integration then gets a **Configure** page, linked from the **Server** or **Site** tab of the Plugins page. It takes the same `App\SourceControl\Setting`s as a source control provider's settings, and secrets are stored encrypted and never shown again:

| Method | What it must do |
|---|---|
| `settings(): array` | The `Setting`s to show, in order. |
| `settingsDescription(): string` | What the settings are for, shown at the top of the page. |

```php
use App\Plugins\HasPluginSettings;
use App\Plugins\PluginSettings;
use App\SourceControl\Setting;

class UptimeIntegration implements ServerIntegration, HasPluginSettings
{
    public function settings(): array
    {
        return [
            new Setting('api_key', __('API key'), type: Setting::SECRET),
            new Setting('region', __('Region'), required: false, placeholder: 'eu'),
        ];
    }

    public function settingsDescription(): string
    {
        return __('Connect Swage to your uptime monitoring account.');
    }

    // ...
}
```

Read values with `app(PluginSettings::class)->get($integration, 'api_key')`, and check that every required setting has one with `isConfigured($integration)`. Server and site integrations keep their settings apart, even with the same `id`. If the plugin also has to run on older versions of Swage, register a subclass that implements the interface only when `interface_exists(HasPluginSettings::class)`, as the Security scanner does.

### Badging servers that need looking at

A server integration can say when a server has something for people to look at, such as open alerts, by also implementing `App\Plugins\HasAttention`, which needs Swage v1.3.3 or later. Swage then shows a badge with the count on the server's card on the servers page, on the server's **Plugins** tab and on the integration's own tab. The badge is red, amber or grey for the most severe thing counted, and several plugins' counts on one server add up into one badge.

| Method | What it must do |
|---|---|
| `attention(Collection $servers): array` | Given an Eloquent collection of servers, return an `App\Plugins\Attention` for each server that has something to look at, keyed by server id, and leave the others out. The servers page asks about every server it lists in one call, so read them together rather than one query per server. |

```php
use App\Plugins\Attention;
use App\Plugins\HasAttention;
use Illuminate\Database\Eloquent\Collection;

class UptimeIntegration implements ServerIntegration, HasAttention
{
    public function attention(Collection $servers): array
    {
        return Incident::query()->whereIn('server_id', $servers->modelKeys())->open()->get()
            ->groupBy('server_id')
            ->map(fn ($incidents) => new Attention($incidents->count(), Attention::HIGH, trans_choice(':count open incident|:count open incidents', $incidents->count())))
            ->all();
    }

    // ...
}
```

`Attention` takes the count, a severity of `Attention::HIGH`, `Attention::MEDIUM` or `Attention::LOW`, and a short label shown when someone hovers over the badge. If the method throws, Swage reports the error and shows no badge from that integration, so the servers page still loads.

### Sending alerts

A plugin can tell people about something on a server with `App\Notifications\ServerAlert`, which needs Swage v1.3.16 or later. The alert reaches the server's owner and Managers under **Notifications**, by email unless they've turned plugin alerts off, and on the server's webhooks. See [Notifications](/docs/notifications).

```php
use App\Notifications\ServerAlert;
use App\Plugins\Attention;

if (class_exists(ServerAlert::class)) {
    ServerAlert::send(
        server: $server,
        source: 'Uptime',
        title: 'Site is down',
        message: 'example.com has not answered for 5 minutes.',
        severity: Attention::HIGH,
        link: route('servers.plugins', ['server' => $server, 'plugin' => 'uptime']),
    );
}
```

The title shown is `source: title on server`. `severity` is `Attention::HIGH`, `MEDIUM` or `LOW`, and `link` defaults to the server's page. Send an alert once for each new thing, not on every scan, and check that the class exists so the plugin still runs on older versions of Swage. A severity Swage doesn't know throws an `InvalidArgumentException`. Otherwise sending never throws: a failure to deliver is reported, and the plugin's work carries on.

### Adding deployment steps

A site integration can add steps to every deployment by also implementing `App\Sites\HooksIntoDeployments`. Each step is bash that runs as the site's user, and can use the [deploy script's variables](/docs/deployments#variables), such as `$SWAGE_RELEASE_DIRECTORY` and `$SWAGE_COMMIT_HASH`.

| Method | When its steps run |
|---|---|
| `afterCreatingRelease(Site $site): string` | Right after `$CREATE_RELEASE()`, once the release holds the new commit and before it is built. |
| `afterActivatingRelease(Site $site): string` | Right after `$ACTIVATE_RELEASE()`, once the release is live. |

Return an empty string to add nothing, such as for a site the integration is not set up for. Steps are only added where the deploy script uses those macros.

```php
use App\Sites\HooksIntoDeployments;

class MonitoringIntegration implements SiteIntegration, HooksIntoDeployments
{
    public function afterActivatingRelease(Site $site): string
    {
        return 'curl -fsS -X POST https://monitor.example.com/deploys -d "commit=$SWAGE_COMMIT_HASH"';
    }

    // ...
}
```

## Releasing

Swage offers updates through version tags, so publish each release as a semver tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Pre-releases such as `v2.0.0-beta` are not offered.

## Sharing a plugin

Anyone can install your plugin from its repository: share its git URL, and an administrator installs it with **Install from a repository** on the **Plugins** page. See [Installing from a repository](/docs/plugins#installing-from-a-repository). It is marked **Unofficial**, and it is updated from your version tags.

## Becoming official

Official plugins are listed in the [plugin index](https://github.com/Swage-Deploy/plugin-repo), and their version tags are signed with a Swage release key. To propose yours, open a pull request adding it to `plugins.json`. See [Plugins](/docs/plugins#official-plugins) for the fields. Once listed, each release needs a tag signed by the Swage maintainers before panels install it.
