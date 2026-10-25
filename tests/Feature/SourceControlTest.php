<?php

use App\Deployments\DeployScript;
use App\Enums\ResourceStatus;
use App\Jobs\DeploySite;
use App\Jobs\InstallRepository;
use App\Jobs\RemoveSite;
use App\Models\ProviderSetting;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use App\SourceControl\ConnectedAccount;
use App\SourceControl\HasSettings;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Setting;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Two\InvalidStateException;
use Livewire\Livewire;
use Tests\Fakes\FakeConfigurableProvider;
use Tests\Fakes\FakeSourceControlProvider;

function configurableProvider(): FakeConfigurableProvider
{
    return tap(new FakeConfigurableProvider('hub', 'Hub'), fn (FakeConfigurableProvider $provider) => app(SourceControlProviders::class)->register($provider));
}

function fakeProvider(): FakeSourceControlProvider
{
    return tap(new FakeSourceControlProvider, fn (FakeSourceControlProvider $provider) => app(SourceControlProviders::class)->register($provider));
}

test('no source control provider is built in, so every one comes from a plugin', function () {
    expect(app(SourceControlProviders::class)->all())->toBe([]);
});

test('providers need a valid id that is not reserved for custom repositories', function (string $id) {
    expect(fn () => app(SourceControlProviders::class)->register(new FakeSourceControlProvider($id)))
        ->toThrow(InvalidArgumentException::class);
})->with(['custom', 'Upper', 'has space', '../escape', '']);

test('a registered class must implement the provider contract', function () {
    expect(fn () => app(SourceControlProviders::class)->register(stdClass::class))
        ->toThrow(InvalidArgumentException::class, SourceControlProvider::class);
});

test('connecting an account stores it per provider, replacing an earlier connection', function () {
    fakeProvider();
    $user = User::factory()->create();
    SourceControlConnection::factory()->for($user)->create(['provider' => 'provision-git', 'username' => 'old']);

    $this->actingAs($user)->get(route('source-control.callback', 'provision-git'))->assertRedirect(route('source-control.edit'));

    $connection = $user->sourceControlConnection('provision-git');

    expect($user->sourceControlConnections()->count())->toBe(1)
        ->and($connection->username)->toBe('jane')
        ->and($connection->token)->toBe('fake-token')
        ->and($connection->refresh_token)->toBe('fake-refresh')
        ->and($connection->expires_at)->not->toBeNull();
});

test('unknown and unconfigured providers cannot be connected', function () {
    configurableProvider();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('source-control.redirect', 'gitea'))->assertNotFound();
    $this->actingAs($user)->get(route('source-control.redirect', 'hub'))->assertNotFound();
});

test('the settings page lists every provider with how to connect it', function () {
    fakeProvider();
    configurableProvider();
    $user = User::factory()->create();
    SourceControlConnection::factory()->for($user)->create(['provider' => 'provision-git', 'username' => 'jane']);

    $this->actingAs($user)->get(route('source-control.edit'))
        ->assertOk()
        ->assertSee('Provision Git')
        ->assertSee('jane')
        ->assertSee('Hub needs to be configured by an administrator');

    $this->actingAs($user)->get('/settings/github')->assertRedirect('/settings/source-control');
});

test('disconnecting forgets only that provider', function () {
    fakeProvider();
    $user = User::factory()->create();
    SourceControlConnection::factory()->for($user)->create(['provider' => 'provision-git']);
    SourceControlConnection::factory()->for($user)->create(['provider' => 'github']);

    Livewire::actingAs($user)->test('pages::settings.source-control')->call('disconnect', 'provision-git');

    expect($user->sourceControlConnections()->pluck('provider')->all())->toBe(['github']);
});

test('a repository is installed from another provider, which records its clone url', function () {
    $provider = fakeProvider();
    $shell = fakeShell();
    $site = Site::factory()->create();
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'provision-git']);

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->assertSet('source', 'provision-git')
        ->set('owner', 'team/platform')
        ->set('repository', 'team/platform/api')
        ->assertSet('branch', 'trunk')
        ->call('installRepository')
        ->assertHasNoErrors();

    $site->refresh();

    expect($site)
        ->source_control->toBe('provision-git')
        ->repository->toBe('team/platform/api')
        ->clone_url->toBe('git@git.example.com:team/platform/api.git')
        ->deploy_key_id->toBe('key-1')
        ->status->toBe(ResourceStatus::Installed)
        ->and($provider->client->calls[0][0])->toBe('addDeployKey')
        ->and($provider->client->calls[0][1][2])->toBe($site->deploy_key_public);

    $shell->assertRan($site->deployKeyPath());
});

test('quick deploy creates a webhook on the site\'s provider that its pushes deploy through', function () {
    $provider = fakeProvider();
    Queue::fake([DeploySite::class]);
    $site = Site::factory()->withRepository()->create(['source_control' => 'provision-git', 'repository' => 'team/api']);
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'provision-git']);

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('quick_deploy', true);

    $site->refresh();
    [$method, [$repository, $url, $secret]] = $provider->client->calls[0];

    expect($method)->toBe('createWebhook')
        ->and($repository)->toBe('team/api')
        ->and($url)->toBe(route('webhooks.source-control', ['provision-git', $site]))
        ->and($site->webhook_id)->toBe('hook-1')
        ->and($site->webhook_secret)->toBe($secret);

    $this->postJson($url, ['event' => 'push', 'branch' => 'main', 'user' => 'jane'], ['X-Fake-Token' => 'wrong'])->assertForbidden();
    $this->postJson($url, ['event' => 'ping'], ['X-Fake-Token' => $secret])->assertNoContent();
    $this->postJson($url, ['event' => 'push', 'branch' => 'feature', 'user' => 'jane'], ['X-Fake-Token' => $secret])->assertNoContent();
    Queue::assertNothingPushed();

    $this->postJson($url, ['event' => 'push', 'branch' => 'main', 'user' => 'jane'], ['X-Fake-Token' => $secret])->assertStatus(202);
    Queue::assertPushed(DeploySite::class);
    expect($site->deployments()->sole()->triggered_by)->toBe('Push by jane');
});

test('a webhook is only accepted on the url of the site\'s own provider', function () {
    fakeProvider();
    $site = Site::factory()->withRepository()->create(['quick_deploy' => true, 'webhook_secret' => 'secret']);

    $this->postJson(route('webhooks.source-control', ['provision-git', $site]), ['event' => 'push', 'branch' => 'main'], ['X-Fake-Token' => 'secret'])->assertNotFound();
    $this->postJson(route('webhooks.source-control', ['gitea', $site]))->assertNotFound();
});

test('a custom repository gets a deploy key for the user to add, and never calls a provider', function () {
    Http::fake();
    $shell = fakeShell();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('source', 'custom')
        ->set('repository', 'git@git.example.com:team/app.git')
        ->set('branch', 'main')
        ->call('installRepository')
        ->assertHasNoErrors();

    $site->refresh();

    expect($site)
        ->source_control->toBe('custom')
        ->clone_url->toBe('git@git.example.com:team/app.git')
        ->deploy_key_id->toBeNull()
        ->status->toBe(ResourceStatus::Installed)
        ->and($site->deploy_key_public)->toStartWith('ssh-ed25519 ')
        ->and($site->acceptsDeployments())->toBeTrue()
        ->and($site->supportsQuickDeploy())->toBeFalse();

    Http::assertNothingSent();
    $shell->assertRan($site->deployKeyPath());

    $this->actingAs($site->server->user)->get(route('servers.sites.show', [$site->server, $site]))
        ->assertSee($site->deploy_key_public)
        ->assertDontSee('Quick deploy');
});

test('reinstalling a custom repository keeps the deploy key the user already added', function () {
    fakeShell();
    $site = Site::factory()->withCustomRepository()->create(['status' => ResourceStatus::Failed]);
    $key = $site->deploy_key_public;

    InstallRepository::dispatchSync($site);

    expect($site->fresh()->deploy_key_public)->toBe($key);
});

test('switching to a different repository generates a new deploy key', function () {
    Queue::fake();
    $site = Site::factory()->withCustomRepository()->create(['status' => ResourceStatus::Failed, 'deploy_key_id' => null]);

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('repository', 'git@git.example.com:team/other.git')
        ->call('installRepository')
        ->assertHasNoErrors();

    expect($site->fresh()->deploy_key_public)->toBeNull();
});

test('clone urls are limited to ssh urls that are safe to quote into shell commands', function (string $url) {
    Queue::fake();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('source', 'custom')
        ->set('repository', $url)
        ->set('branch', 'main')
        ->call('installRepository')
        ->assertHasErrors(['repository']);

    Queue::assertNothingPushed();
})->with([
    'https url' => ['https://github.com/acme/app.git'],
    'quote' => ["git@host:acme/app.git' && reboot '"],
    'option' => ['--upload-pack=touch /tmp/x'],
    'spaces' => ['git@host:acme/my app.git'],
]);

test('ssh clone urls in both forms are accepted', function (string $url) {
    Queue::fake();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('source', 'custom')
        ->set('repository', $url)
        ->set('branch', 'main')
        ->call('installRepository')
        ->assertHasNoErrors();
})->with([
    'scp style' => ['git@git.example.com:team/app.git'],
    'ssh url with port' => ['ssh://git@git.example.com:2222/team/app.git'],
]);

test('quick deploy cannot be turned on for a custom repository', function () {
    $site = Site::factory()->withCustomRepository()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('quick_deploy', true)
        ->assertStatus(409);

    expect($site->fresh()->quick_deploy)->toBeFalse();
});

test('removing sites revokes deploy keys and webhooks through each site\'s provider and skips custom repositories', function () {
    $provider = fakeProvider();
    fakeShell();
    $site = Site::factory()->withRepository()->create(['source_control' => 'provision-git', 'repository' => 'team/api', 'deploy_key_id' => 'key-9', 'webhook_id' => 'hook-9']);
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'provision-git']);
    $custom = Site::factory()->withCustomRepository()->for($site->server)->create();

    RemoveSite::dispatchSync($site);
    RemoveSite::dispatchSync($custom);

    expect($provider->client->calls)->toBe([
        ['deleteDeployKey', ['team/api', 'key-9']],
        ['deleteWebhook', ['team/api', 'hook-9']],
    ]);
});

test('deployments keep working from the recorded clone url when the provider is no longer installed', function () {
    $site = Site::factory()->withRepository()->create(['source_control' => 'removed-plugin', 'clone_url' => 'git@git.example.com:team/api.git']);

    expect($site->cloneUrl())->toBe('git@git.example.com:team/api.git')
        ->and($site->acceptsDeployments())->toBeTrue()
        ->and(DeployScript::render($site))->toContain("'git@git.example.com:team/api.git'");
});

test('existing github connections and sites are moved onto the github provider, and back on rollback', function () {
    $migration = require database_path('migrations/2026_10_20_193813_support_source_control_providers.php');
    $migration->down();

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['github_id' => '77', 'github_username' => 'jane', 'github_token' => Crypt::encryptString('gho_secret')]);
    $site = Site::factory()->make();
    DB::table('sites')->insert([
        ...Arr::only($site->getAttributes(), ['server_id', 'domain', 'php_version', 'deploy_token']),
        'id' => 501,
        'repository' => 'acme/app',
        'github_deploy_key_id' => 123,
        'github_webhook_id' => 99,
    ]);

    $migration->up();

    $connection = $user->sourceControlConnection('github');
    $migrated = Site::find(501);

    expect($connection)
        ->username->toBe('jane')
        ->account_id->toBe('77')
        ->token->toBe('gho_secret')
        ->and($migrated)
        ->source_control->toBe('github')
        ->clone_url->toBe('git@github.com:acme/app.git')
        ->deploy_key_id->toBe('123')
        ->webhook_id->toBe('99')
        ->and(Schema::hasColumn('users', 'github_token'))->toBeFalse();

    $migration->down();

    expect(Crypt::decryptString(DB::table('users')->where('id', $user->id)->value('github_token')))->toBe('gho_secret')
        ->and(DB::table('sites')->where('id', 501)->value('github_deploy_key_id'))->toBe(123);

    $migration->up();
});

function configureAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('provider config pages are for administrators who have just confirmed their password', function () {
    configurableProvider();

    $this->actingAs(User::factory()->create())->get(route('source-control.configure', 'hub'))->assertForbidden();
    $this->actingAs($admin = configureAdmin())->get(route('source-control.configure', 'hub'))->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('source-control.configure', 'hub'))
        ->assertOk()
        ->assertSee('Configure Hub')
        ->assertSee(route('source-control.callback', 'hub'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('source-control.configure', 'gitea'))->assertNotFound();
});

test('the plugins page lists source control providers with whether each is configured', function () {
    configurableProvider();

    $this->actingAs(User::factory()->create())->get(route('plugins'))->assertForbidden();

    $this->actingAs(configureAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('Hub')
        ->assertSee('Not configured')
        ->assertSee(route('source-control.configure', 'hub'))
        ->assertSee(route('plugins'));
});

test('the plugins page says when no source control provider is installed', function () {
    $this->actingAs(configureAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('No source control plugins are installed');
});

test('saved secrets are never sent back to the browser and a blank field keeps them', function () {
    configurableProvider();
    app(ProviderSettings::class)->put('hub', ['client_id' => 'page-client', 'client_secret' => 'page-secret']);
    $admin = configureAdmin();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('source-control.configure', 'hub'))
        ->assertSee('page-client')
        ->assertDontSee('page-secret');

    Livewire::actingAs($admin)->test('pages::source-control.configure', ['provider' => 'hub'])
        ->assertSet('values.client_secret', '')
        ->set('values.client_id', 'new-client')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ProviderSettings::class)->all('hub'))->toBe(['client_id' => 'new-client', 'client_secret' => 'page-secret'])
        ->and(ProviderSetting::where('key', 'client_secret')->value('value'))->toBe('page-secret')
        ->and(DB::table('provider_settings')->where('key', 'client_secret')->value('value'))->not->toContain('page-secret')
        ->and(app(SourceControlProviders::class)->get('hub')->isConfigured())->toBeTrue();
});

test('required settings must be filled in, and a saved secret can be removed', function () {
    configurableProvider();
    $admin = configureAdmin();

    Livewire::actingAs($admin)->test('pages::source-control.configure', ['provider' => 'hub'])
        ->call('save')
        ->assertHasErrors(['values.client_id' => 'required', 'values.client_secret' => 'required']);

    app(ProviderSettings::class)->put('hub', ['client_id' => 'page-client', 'client_secret' => 'page-secret']);

    Livewire::actingAs($admin)->test('pages::source-control.configure', ['provider' => 'hub'])
        ->call('clearSecret', 'client_secret')
        ->assertSet('secretsSet.client_secret', false)
        ->call('clearSecret', 'client_id')
        ->assertNotFound();

    expect(app(SourceControlProviders::class)->get('hub')->isConfigured())->toBeFalse();
});

test('a plugin\'s declared settings appear on its config page and are validated by type', function () {
    $provider = new class extends FakeSourceControlProvider implements HasSettings
    {
        public function settings(): array
        {
            return [
                new Setting('server_url', 'Server URL', type: Setting::URL),
                new Setting('token', 'Token', type: Setting::SECRET, required: false),
            ];
        }
    };
    app(SourceControlProviders::class)->register($provider);

    Livewire::actingAs(configureAdmin())->test('pages::source-control.configure', ['provider' => 'provision-git'])
        ->assertSee('Server URL')
        ->set('values.server_url', 'not a url')
        ->call('save')
        ->assertHasErrors(['values.server_url' => 'url'])
        ->set('values.server_url', 'https://git.example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ProviderSettings::class)->get('provision-git', 'server_url'))->toBe('https://git.example.com')
        ->and(app(ProviderSettings::class)->get('provision-git', 'token'))->toBeNull();
});

test('providers without settings have no config page', function () {
    fakeProvider();

    Livewire::actingAs(configureAdmin())->test('pages::source-control.configure', ['provider' => 'provision-git'])->assertNotFound();
});

test('the source control page names providers sites still use that no plugin provides', function () {
    Site::factory()->withRepository()->create();
    Site::factory()->withCustomRepository()->create();
    SourceControlConnection::factory()->create(['provider' => 'gitlab']);

    $this->actingAs(configureAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertSee('Sites or accounts use github, gitlab, which no installed plugin provides');

    fakeSourceControl('github');
    fakeSourceControl('gitlab');

    $this->actingAs(configureAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertDontSee('which no installed plugin provides');
});

test('people are told when no source control provider is installed yet', function () {
    $this->actingAs(User::factory()->create())->get(route('source-control.edit'))
        ->assertOk()
        ->assertSee('No source control providers are installed yet');
});

/**
 * Register a provider whose callback fails the way a real provider's does, wrapping the cause.
 */
function failingProvider(?Throwable $cause): void
{
    app(SourceControlProviders::class)->register(new class($cause) extends FakeSourceControlProvider
    {
        public function __construct(public ?Throwable $cause)
        {
            parent::__construct('hub', 'Hub');
        }

        public function connectedAccount(Request $request): ConnectedAccount
        {
            throw new SourceControlException('Hub did not authorize the connection.', previous: $this->cause);
        }
    });
}

test('a failed connection says why', function (array $query, ?Throwable $cause, string $message) {
    failingProvider($cause);

    $this->actingAs(User::factory()->create())
        ->get(route('source-control.callback', ['provider' => 'hub', ...$query]))
        ->assertRedirect(route('source-control.edit'))
        ->assertSessionHas('source-control-error', fn (string $error) => str_contains($error, $message));
})->with([
    'cancelled' => [['error' => 'access_denied'], null, 'The connection was cancelled on Hub.'],
    'callback url mismatch' => [['error' => 'redirect_uri_mismatch'], null, 'Set the OAuth app\'s callback URL to '],
    'other provider error' => [['error' => 'application_suspended', 'error_description' => 'Your application has been suspended.'], null, 'Hub refused the connection: Your application has been suspended.'],
    'reply reached another session' => [[], new InvalidStateException, 'but your sign-in was not found there'],
    'wrong client credentials' => [[], new ClientException('Unauthorized', new GuzzleRequest('GET', 'https://hub.example.com/user'), new GuzzleResponse(401)), 'did not accept Swage\'s client ID and secret'],
    'anything else' => [[], new RuntimeException('Connection timed out'), 'Hub did not authorize the connection. (Connection timed out)'],
]);

test('a failed repository install shows why on the site\'s page', function () {
    $provider = fakeSourceControl('github');
    $provider->client->failAddingDeployKey = 'GitHub does not allow deploy keys on acme/app.';
    $site = Site::factory()->withRepository()->create(['deploy_key_private' => null, 'deploy_key_public' => null, 'deploy_key_id' => null]);
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'github']);

    rescue(fn () => InstallRepository::dispatchSync($site), report: false);

    expect($site->fresh())->status->toBe(ResourceStatus::Failed)->install_error->toBe('GitHub does not allow deploy keys on acme/app.');

    $this->actingAs($site->server->user)->get(route('servers.sites.show', [$site->server, $site]))
        ->assertSee('The repository could not be installed')
        ->assertSee('GitHub does not allow deploy keys on acme/app.');
});
