<?php

use App\Enums\DeploymentStatus;
use App\Jobs\StartSelfUpdate;
use App\Models\SelfUpdate;
use App\Models\User;
use App\Plugins\Plugin;
use App\Plugins\PluginCategory;
use App\Plugins\PluginIndex;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginKind;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use App\SourceControl\SourceControlProviders;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const PLUGIN_COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';

/**
 * The plugin repository's refs: version tags up to v1.2.0, which points to PLUGIN_COMMIT, and main as its default branch.
 */
const PLUGIN_REFS = "ref: refs/heads/main\tHEAD\n"
    ."5555555555555555555555555555555555555555\tHEAD\n"
    ."5555555555555555555555555555555555555555\trefs/heads/main\n"
    ."9999999999999999999999999999999999999999\trefs/tags/v1.1.0\n"
    .PLUGIN_COMMIT."\trefs/tags/v1.2.0\n";

beforeEach(function () {
    $this->root = storage_path('framework/testing/plugins-'.uniqid());
    $this->release = $this->root.'/releases/20260101000000';

    File::ensureDirectoryExists($this->release);
    File::ensureDirectoryExists($this->root.'/shared/storage');
    File::put($this->root.'/shared/.env', 'APP_ENV=production');
    File::put($this->root.'/swage-host-migrate', '');
    symlink($this->release, $this->root.'/current');

    config([
        'swage.self_update.host_migrator' => $this->root.'/swage-host-migrate',
        'swage.plugins.manifest' => $this->root.'/shared/storage/app/plugins.json',
        'swage.plugins.directory' => $this->root.'/shared/storage/app/plugins',
    ]);

    $this->app->instance(SelfUpdater::class, new SelfUpdater($this->release));

    $this->composer = [
        'name' => 'acme/swage-gitea',
        'type' => 'swage-plugin',
        'extra' => [
            'swage' => ['contract' => SourceControlProviders::CONTRACT_VERSION, 'kind' => 'source-control'],
            'laravel' => ['providers' => ['Acme\\SwageGitea\\GiteaServiceProvider']],
        ],
    ];

    fakePluginIndex();

    Sleep::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

const PLUGIN_INDEX_URL = 'https://plugins.example.com/plugins.json';

/**
 * Fake the plugin index, which lists acme/swage-gitea unless given other entries. The fake serves whatever the latest
 * call listed, since a later Http::fake() would not replace an earlier one.
 */
function fakePluginIndex(?array $plugins = null): void
{
    $GLOBALS['fakePluginIndexEntries'] = $plugins ?? [[
        'name' => 'acme/swage-gitea',
        'title' => 'Gitea',
        'description' => 'Gitea as a source control provider.',
        'kind' => 'source-control',
        'repository' => 'https://github.com/acme/swage-gitea.git',
        'homepage' => 'https://github.com/acme/swage-gitea',
    ]];

    config(['swage.plugins.index' => PLUGIN_INDEX_URL]);
    Cache::forget('plugin-index:'.hash('xxh128', PLUGIN_INDEX_URL));

    if ($plugins === null) {
        Http::fake([PLUGIN_INDEX_URL => fn () => Http::response(['plugins' => $GLOBALS['fakePluginIndexEntries']])]);
    }
}

/**
 * Fake git and Composer. Checking out a plugin writes the given composer.json.
 */
function fakePluginRepository(array $composer, array $overrides = [], bool $healthy = true): void
{
    Http::fake(['*/up' => $healthy ? Http::response('OK') : Http::response('Server Error', 500)]);

    Process::fake(array_merge([
        "'git' 'checkout' '--quiet' '--detach' 'FETCH_HEAD'" => function (PendingProcess $process) use ($composer) {
            File::put($process->path.'/composer.json', json_encode($composer));

            return Process::result();
        },
        "'git' 'ls-remote' '--symref' *" => PLUGIN_REFS,
        "'git' 'rev-parse' 'HEAD'" => fn (PendingProcess $process) => Process::result(match (true) {
            str_contains($process->path, '/plugins/') => PLUGIN_COMMIT,
            str_contains($process->path, '20260101000000') => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            default => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        }),
        "'git' 'ls-remote' 'origin'" => "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\trefs/heads/main",
        "'git' 'remote' 'get-url' 'origin'" => 'git@github.com:acme/swage.git',
        "'git' '-c' 'advice.detachedHead=false' 'clone' *" => function (PendingProcess $process) {
            File::ensureDirectoryExists(end($process->command).'/storage');

            return Process::result('Cloned');
        },
    ], $overrides, ['*' => Process::result()]));
}

/**
 * Record a plugin as installed, with a checkout at its commit, without fetching it.
 */
function installedPlugin(string $ref = 'v1.2.0', string $commit = PLUGIN_COMMIT): Plugin
{
    $plugin = new Plugin('acme/swage-gitea', PluginKind::SourceControl, 'https://github.com/acme/swage-gitea.git', $ref, $commit, 'acme-swage-gitea-'.substr($commit, 0, 12), now()->toIso8601String());

    app(PluginManifest::class)->save($plugin);
    File::ensureDirectoryExists(config('swage.plugins.directory').'/'.$plugin->checkout);

    return $plugin;
}

test('a plugin is installed at the newest version tag and records the commit it points to', function () {
    fakePluginRepository($this->composer);

    $plugin = app(PluginInstaller::class)->fetch('https://github.com/acme/swage-gitea.git');

    expect($plugin)
        ->name->toBe('acme/swage-gitea')
        ->kind->toBe(PluginKind::SourceControl)
        ->ref->toBe('v1.2.0')
        ->commit->toBe(PLUGIN_COMMIT)
        ->checkout->toBe('acme-swage-gitea-cccccccccccc')
        ->and(File::exists(config('swage.plugins.directory').'/acme-swage-gitea-cccccccccccc/composer.json'))->toBeTrue()
        ->and(File::directories(config('swage.plugins.directory')))->toHaveCount(1);

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'fetch', '--quiet', '--depth', '1', 'origin', 'v1.2.0']);
});

test('a repository without version tags is installed from its default branch, which it then follows', function () {
    fakePluginRepository($this->composer, ["'git' 'ls-remote' '--symref' *" => "ref: refs/heads/trunk\tHEAD\n".PLUGIN_COMMIT."\trefs/heads/trunk\n4444444444444444444444444444444444444444\trefs/tags/v2.0.0-beta"]);

    expect(app(PluginInstaller::class)->fetch('https://github.com/acme/swage-gitea.git')->ref)->toBe('trunk');
});

test('a specific tag or branch can still be installed from the command line', function () {
    fakePluginRepository($this->composer);

    expect(app(PluginInstaller::class)->fetch('https://github.com/acme/swage-gitea.git', 'v1.1.0')->ref)->toBe('v1.1.0');

    Process::assertNotRan(fn (PendingProcess $process) => in_array('ls-remote', $process->command, true));
});

test('packages that are not plugins for this contract are refused, leaving nothing behind', function (array $changes, string $error) {
    fakePluginRepository(array_replace_recursive($this->composer, $changes));

    expect(fn () => app(PluginInstaller::class)->fetch('https://github.com/acme/swage-gitea.git', 'v1'))
        ->toThrow(RuntimeException::class, $error);

    expect(File::directories(config('swage.plugins.directory')))->toBeEmpty();
})->with([
    'not a swage plugin' => [['type' => 'library'], 'is not a Swage plugin'],
    'older contract' => [['extra' => ['swage' => ['contract' => 0]]], 'version 0 of the plugin contract'],
    'no service provider' => [['extra' => ['laravel' => ['providers' => null]]], 'registers no Laravel service provider'],
    'invalid name' => [['name' => 'Not A Package'], 'is not a valid Composer package name'],
    'no kind' => [['extra' => ['swage' => ['kind' => null]]], 'does not say what it extends'],
    'a kind plugins cannot be yet' => [['extra' => ['swage' => ['kind' => 'deployments']]], 'does not say what it extends'],
]);

test('refs and repositories that could be read as options are refused', function (string $repository, ?string $ref) {
    Process::fake();

    expect(fn () => app(PluginInstaller::class)->fetch($repository, $ref))->toThrow(RuntimeException::class);

    Process::assertNothingRan();
})->with([
    'missing repository' => ['', null],
    'ref as an option' => ['https://github.com/acme/p.git', '--upload-pack=x'],
    'repository as an option' => ['--upload-pack=x', null],
    'repository with spaces' => ['https://github.com/acme/p.git --upload-pack=x', null],
]);

test('each release adds the installed plugins from their pinned checkouts', function () {
    fakePluginRepository($this->composer);
    $installer = app(PluginInstaller::class);
    app(PluginManifest::class)->save($installer->fetch('https://github.com/acme/swage-gitea.git', 'v1.2.0'));

    [$repository, $require, $discover] = $installer->buildSteps();

    expect($repository['command'][0])->toBe('composer')
        ->and($repository['command'][2])->toBe('repositories.swage-plugin-acme-swage-gitea')
        ->and(json_decode($repository['command'][3], true))->toBe([
            'type' => 'path',
            'url' => realpath(config('swage.plugins.directory')).'/acme-swage-gitea-cccccccccccc',
            'options' => ['symlink' => false],
        ])
        ->and($require['command'])->toBe(['composer', 'require', '--no-interaction', '--update-no-dev', '--no-scripts', 'acme/swage-gitea:*@dev'])
        ->and($discover['command'])->toBe(['composer', 'dump-autoload', '--no-interaction', '--optimize', '--no-dev']);
});

test('a release is not built from a plugin checkout that changed after it was installed', function (array $git) {
    installedPlugin();
    fakePluginRepository($this->composer, overrides: $git);

    expect(fn () => app(PluginInstaller::class)->buildSteps())->toThrow(RuntimeException::class, 'has changed since it was installed');
})->with([
    'moved to another commit' => [["'git' 'rev-parse' 'HEAD'" => 'dddddddddddddddddddddddddddddddddddddddd']],
    'files edited' => [["'git' 'status' '--porcelain'" => ' M src/GiteaProvider.php']],
]);

test('installing a plugin rebuilds swage with it', function () {
    fakePluginRepository($this->composer);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea'])
        ->expectsConfirmation('Install this plugin and rebuild Swage with it?', 'yes')
        ->assertSuccessful();

    expect(app(PluginManifest::class)->find('acme/swage-gitea')->commit)->toBe(PLUGIN_COMMIT)
        ->and(SelfUpdate::sole()->status)->toBe(DeploymentStatus::Finished)
        ->and(SelfUpdate::sole()->output)->toContain('Adding the acme/swage-gitea plugin at cccccccccccc', 'Installing plugins');

    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 2) === ['composer', 'require'] && in_array('acme/swage-gitea:*@dev', $process->command, true));
});

test('a plugin is not installed when swage fails to rebuild with it', function () {
    fakePluginRepository($this->composer, overrides: ["'composer' 'require' *" => Process::result(errorOutput: 'Your requirements could not be resolved', exitCode: 2)]);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea', '--force' => true])
        ->assertFailed();

    expect(app(PluginManifest::class)->all())->toBeEmpty()
        ->and(File::directories(config('swage.plugins.directory')))->toBeEmpty()
        ->and(realpath($this->root.'/current'))->toBe(realpath($this->release));
});

test('a failed upgrade keeps the version that was installed', function () {
    $previous = installedPlugin('v1.0.0', str_repeat('e', 40));
    fakePluginRepository($this->composer, overrides: ["'composer' 'require' *" => Process::result(exitCode: 2)]);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea', '--force' => true])
        ->assertFailed();

    expect(app(PluginManifest::class)->find('acme/swage-gitea')->ref)->toBe('v1.0.0')
        ->and(File::isDirectory(config('swage.plugins.directory').'/'.$previous->checkout))->toBeTrue();
});

test('declining the confirmation installs nothing', function () {
    fakePluginRepository($this->composer);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea'])
        ->expectsConfirmation('Install this plugin and rebuild Swage with it?', 'no')
        ->assertFailed();

    expect(app(PluginManifest::class)->all())->toBeEmpty()
        ->and(File::directories(config('swage.plugins.directory')))->toBeEmpty()
        ->and(SelfUpdate::count())->toBe(0);
});

test('plugins cannot be installed outside an installer-created install', function () {
    $this->app->instance(SelfUpdater::class, new SelfUpdater(base_path()));
    Process::fake();

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea'])
        ->expectsOutputToContain('require the plugin with Composer')
        ->assertFailed();

    Process::assertNothingRan();
});

test('removing a plugin rebuilds swage without it and deletes its checkout', function () {
    fakePluginRepository($this->composer);
    $installer = app(PluginInstaller::class);
    app(PluginManifest::class)->save($plugin = $installer->fetch('https://github.com/acme/swage-gitea.git', 'v1.2.0'));

    $this->artisan('swage:plugin:remove', ['name' => 'acme/swage-gitea', '--force' => true])->assertSuccessful();

    expect(app(PluginManifest::class)->all())->toBeEmpty()
        ->and(File::isDirectory(config('swage.plugins.directory').'/'.$plugin->checkout))->toBeFalse();

    Process::assertNotRan(fn (PendingProcess $process) => array_slice($process->command, 0, 2) === ['composer', 'require']);
});

test('installed plugins and providers are listed', function () {
    fakePluginRepository($this->composer);
    app(PluginManifest::class)->save(app(PluginInstaller::class)->fetch('https://github.com/acme/swage-gitea.git', 'v1.2.0'));

    $this->artisan('swage:plugin:list')
        ->expectsOutputToContain('acme/swage-gitea')
        ->expectsOutputToContain('none')
        ->assertSuccessful();

    $this->actingAs(pluginAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('acme/swage-gitea')
        ->assertSee('v1.2.0 · cccccccccccc')
        ->assertSee('Source control');
});

function pluginAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('the plugins page is for administrators who have just confirmed their password', function () {
    $this->actingAs(User::factory()->create())->get(route('plugins'))->assertForbidden();
    $this->actingAs($admin = pluginAdmin())->get(route('plugins'))->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('Available');
});

test('installing from the dashboard runs the install in the background through the queue worker', function () {
    Queue::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->assertSee('Gitea as a source control provider.')
        ->call('install', 'acme/swage-gitea');

    $operation = SelfUpdate::sole();

    expect($operation->plugin_operation)->toBe(['action' => 'install', 'name' => 'acme/swage-gitea'])
        ->and($operation->title())->toBe('Install acme/swage-gitea');

    Queue::assertPushed(StartSelfUpdate::class, fn (StartSelfUpdate $job) => $job->update->is($operation));
});

test('the queue worker starts a plugin install as a detached install command', function () {
    Process::fake();
    $operation = SelfUpdate::factory()->create(['plugin_operation' => ['action' => 'install', 'name' => 'acme/swage-gitea']]);

    (new StartSelfUpdate($operation))->handle(app(SelfUpdater::class));

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "'swage:plugin:install' 'acme/swage-gitea' '--force' '--update={$operation->id}'")
        && str_starts_with($process->command, 'setsid nohup'));
});

test('an install started from the dashboard reports its progress to the dashboard', function () {
    fakePluginRepository($this->composer);
    $operation = SelfUpdate::factory()->create(['status' => DeploymentStatus::Pending, 'plugin_operation' => ['action' => 'install', 'name' => 'acme/swage-gitea']]);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea', '--force' => true, '--update' => $operation->id])
        ->assertSuccessful();

    $operation->refresh();

    expect($operation->status)->toBe(DeploymentStatus::Finished)
        ->and($operation->plugin_operation['name'])->toBe('acme/swage-gitea')
        ->and($operation->title())->toBe('Install acme/swage-gitea at v1.2.0')
        ->and($operation->output)->toContain('==> Fetching acme/swage-gitea from https://github.com/acme/swage-gitea.git', 'Version: v1.2.0 at '.PLUGIN_COMMIT, 'Installing plugins')
        ->and(SelfUpdate::count())->toBe(1);
});

test('a dashboard install that cannot fetch the plugin is marked failed with the reason', function () {
    fakePluginRepository($this->composer, overrides: ["'git' 'fetch' *" => Process::result(errorOutput: 'fatal: repository not found', exitCode: 128)]);
    $operation = SelfUpdate::factory()->create(['status' => DeploymentStatus::Pending, 'plugin_operation' => ['action' => 'install', 'name' => 'acme/swage-gitea']]);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea', '--force' => true, '--update' => $operation->id])
        ->assertFailed();

    expect($operation->fresh())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('fatal: repository not found');
});

test('only plugins the index lists can be installed', function () {
    Queue::fake();
    Process::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('install', 'acme/not-listed')
        ->assertNotFound();

    $this->artisan('swage:plugin:install', ['name' => 'acme/not-listed', '--force' => true])
        ->expectsOutputToContain('is not in the plugin index')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNotRan(fn (PendingProcess $process) => in_array('fetch', $process->command, true));
    expect(SelfUpdate::count())->toBe(0);
});

test('a listed repository must hold the package the index lists', function () {
    fakePluginRepository([...$this->composer, 'name' => 'acme/something-else']);

    $this->artisan('swage:plugin:install', ['name' => 'acme/swage-gitea', '--force' => true])
        ->expectsOutputToContain('holds acme/something-else')
        ->assertFailed();

    expect(app(PluginManifest::class)->all())->toBeEmpty()
        ->and(File::directories(config('swage.plugins.directory')))->toBeEmpty();
});

test('the index\'s malformed entries are left out and installed plugins are not offered again', function () {
    fakePluginIndex([
        ['name' => 'acme/swage-gitea', 'title' => 'Gitea', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-gitea.git'],
        ['name' => 'acme/swage-forgejo', 'title' => 'Forgejo', 'description' => 'Forgejo support.', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-forgejo.git'],
        ['name' => 'acme/no-kind', 'repository' => 'https://github.com/acme/no-kind.git'],
        ['name' => 'acme/unknown-kind', 'kind' => 'deployments', 'repository' => 'https://github.com/acme/unknown-kind.git'],
        ['name' => 'acme/local-path', 'kind' => 'source-control', 'repository' => '/etc/passwd'],
        'not an object',
    ]);
    installedPlugin();

    expect(array_keys(app(PluginIndex::class)->all()))->toBe(['acme/swage-gitea', 'acme/swage-forgejo']);

    $page = Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->assertSee('Forgejo support.')
        ->assertDontSee('acme/no-kind');

    expect(array_keys($page->instance()->available))->toBe(['acme/swage-forgejo']);
});

test('an index that cannot be reached is reported and nothing can be installed', function () {
    config(['swage.plugins.index' => 'https://plugins.example.com/missing.json']);
    Http::fake(['plugins.example.com/missing.json' => Http::response('Not Found', 404)]);
    Queue::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->assertSee('Unable to read the plugin index')
        ->call('install', 'acme/swage-gitea')
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a plugin that is no longer listed is not offered or given updates', function () {
    installedPlugin('v1.1.0', '9999999999999999999999999999999999999999');
    fakePluginRepository($this->composer);
    fakePluginIndex([]);

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('checkForUpdates')
        ->assertSet('updates', [])
        ->assertSee('no longer listed');

    $this->artisan('swage:plugin:update', ['--force' => true])
        ->expectsOutputToContain('is no longer in the plugin index')
        ->assertSuccessful();

    expect(app(PluginManifest::class)->find('acme/swage-gitea')->ref)->toBe('v1.1.0');
    Process::assertNotRan(fn (PendingProcess $process) => in_array('fetch', $process->command, true));
});

test('plugin changes wait for a running update or plugin change to finish', function () {
    Queue::fake();
    SelfUpdate::factory()->create(['status' => DeploymentStatus::Running]);

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('install', 'acme/swage-gitea');

    Queue::assertNothingPushed();
    expect(SelfUpdate::count())->toBe(1);
});

test('opening the plugins page checks every plugin for a newer version tag', function () {
    installedPlugin('v1.2.0');
    Process::fake(["'git' 'ls-remote' *" => implode("\n", [
        PLUGIN_COMMIT."\trefs/tags/v1.2.0",
        "1111111111111111111111111111111111111111\trefs/tags/v1.10.0",
        "2222222222222222222222222222222222222222\trefs/tags/v1.10.0^{}",
        "3333333333333333333333333333333333333333\trefs/tags/v1.9.0",
        "4444444444444444444444444444444444444444\trefs/tags/v2.0.0-beta",
        "5555555555555555555555555555555555555555\trefs/heads/main",
    ])]);
    Queue::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('checkForUpdates')
        ->assertSet('updates.acme/swage-gitea', ['ref' => 'v1.10.0', 'commit' => '2222222222222222222222222222222222222222'])
        ->assertSee('v1.10.0 available')
        ->call('updatePlugin', 'acme/swage-gitea');

    expect(SelfUpdate::sole()->plugin_operation)->toBe(['action' => 'update', 'names' => ['acme/swage-gitea']]);
    Queue::assertPushed(StartSelfUpdate::class);
});

test('update checks are remembered for an hour unless checked again', function () {
    installedPlugin('v1.2.0');
    Process::fake(["'git' 'ls-remote' *" => PLUGIN_REFS]);

    Livewire::actingAs($admin = pluginAdmin())->test('pages::plugins.index')->call('checkForUpdates');
    Livewire::actingAs($admin)->test('pages::plugins.index')->call('checkForUpdates')->assertSet('updates.acme/swage-gitea', false);

    Process::assertRanTimes(fn (PendingProcess $process) => in_array('ls-remote', $process->command, true), 1);

    Livewire::actingAs($admin)->test('pages::plugins.index')->call('checkForUpdates', true);

    Process::assertRanTimes(fn (PendingProcess $process) => in_array('ls-remote', $process->command, true), 2);
});

test('a plugin whose repository cannot be reached is marked as not checked', function () {
    installedPlugin('v1.2.0');
    Process::fake(["'git' 'ls-remote' *" => Process::result(errorOutput: 'fatal: unable to access', exitCode: 128)]);

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('checkForUpdates')
        ->assertSet('updates.acme/swage-gitea', null)
        ->assertSee('could not check');
});

test('a plugin from a repository without version tags is offered its branch\'s new commits', function () {
    installedPlugin('main');
    Process::fake(["'git' 'ls-remote' *" => "ref: refs/heads/main\tHEAD\n5555555555555555555555555555555555555555\trefs/heads/main"]);

    expect(app(PluginInstaller::class)->availableUpdate(app(PluginManifest::class)->find('acme/swage-gitea')))
        ->toBe(['ref' => 'main', 'commit' => '5555555555555555555555555555555555555555']);
});

test('a plugin installed from a branch moves to version tags once its repository has them', function () {
    $installer = app(PluginInstaller::class);

    installedPlugin('main', '5555555555555555555555555555555555555555');
    Process::fake(["'git' 'ls-remote' *" => PLUGIN_REFS]);

    expect($installer->availableUpdate(app(PluginManifest::class)->find('acme/swage-gitea')))
        ->toBe(['ref' => 'v1.2.0', 'commit' => PLUGIN_COMMIT]);
});

test('a plugin on a branch at the newest tag\'s commit is up to date, and is not offered later branch commits', function () {
    installedPlugin('main', PLUGIN_COMMIT);
    Process::fake(["'git' 'ls-remote' *" => "ref: refs/heads/main\tHEAD\n5555555555555555555555555555555555555555\trefs/heads/main\n".PLUGIN_COMMIT."\trefs/tags/v1.2.0"]);

    expect(app(PluginInstaller::class)->availableUpdate(app(PluginManifest::class)->find('acme/swage-gitea')))->toBeNull();
});

test('a plugin at the newest version is up to date and cannot be updated', function () {
    installedPlugin('v1.2.0');
    Process::fake(["'git' 'ls-remote' *" => PLUGIN_REFS]);

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('checkForUpdates')
        ->assertSet('updates.acme/swage-gitea', false)
        ->assertSee('up to date')
        ->call('updatePlugin', 'acme/swage-gitea')
        ->assertStatus(409);
});

test('every plugin with a newer version is updated in a single rebuild', function () {
    installedPlugin('v1.1.0', '9999999999999999999999999999999999999999');
    fakePluginIndex([
        ['name' => 'acme/swage-gitea', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-gitea.git'],
        ['name' => 'acme/swage-bitbucket', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-bitbucket.git'],
    ]);
    $other = new Plugin('acme/swage-bitbucket', PluginKind::SourceControl, 'https://github.com/acme/swage-bitbucket.git', 'main', '6666666666666666666666666666666666666666', 'acme-swage-bitbucket-666666666666', now()->toIso8601String());
    app(PluginManifest::class)->save($other);
    Process::fake(["'git' 'ls-remote' *" => PLUGIN_REFS]);
    Queue::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')
        ->call('checkForUpdates')
        ->assertSee('Update all')
        ->call('updateAll');

    expect(SelfUpdate::sole()->plugin_operation)->toBe(['action' => 'update', 'names' => ['acme/swage-bitbucket', 'acme/swage-gitea']])
        ->and(SelfUpdate::sole()->title())->toBe('Update acme/swage-bitbucket, acme/swage-gitea');
});

test('updating a plugin rebuilds swage with its newest version', function () {
    installedPlugin('v1.1.0', '9999999999999999999999999999999999999999');
    fakePluginRepository($this->composer);

    $this->artisan('swage:plugin:update', ['--force' => true])->assertSuccessful();

    expect(app(PluginManifest::class)->find('acme/swage-gitea'))
        ->ref->toBe('v1.2.0')
        ->commit->toBe(PLUGIN_COMMIT)
        ->and(SelfUpdate::sole()->status)->toBe(DeploymentStatus::Finished)
        ->and(File::directories(config('swage.plugins.directory')))->toHaveCount(1);
});

test('plugins that fail to rebuild are all put back at their previous versions', function () {
    $previous = installedPlugin('v1.1.0', '9999999999999999999999999999999999999999');
    fakePluginRepository($this->composer, ["'composer' 'require' *" => Process::result(exitCode: 2)]);

    $this->artisan('swage:plugin:update', ['--force' => true])->assertFailed();

    expect(app(PluginManifest::class)->find('acme/swage-gitea'))
        ->ref->toBe('v1.1.0')
        ->commit->toBe($previous->commit)
        ->and(File::isDirectory(config('swage.plugins.directory').'/'.$previous->checkout))->toBeTrue();
});

test('plugins that are already up to date are not rebuilt', function () {
    installedPlugin('v1.2.0');
    fakePluginRepository($this->composer);
    $operation = SelfUpdate::factory()->create(['status' => DeploymentStatus::Pending, 'plugin_operation' => ['action' => 'update', 'names' => ['acme/swage-gitea']]]);

    $this->artisan('swage:plugin:update', ['names' => ['acme/swage-gitea'], '--force' => true, '--update' => $operation->id])->assertSuccessful();

    expect($operation->fresh())->status->toBe(DeploymentStatus::Finished)->output->toContain('Every plugin is up to date');
    Process::assertNotRan(fn (PendingProcess $process) => array_slice($process->command, 0, 2) === ['composer', 'require']);
});

test('removing from the dashboard runs the removal in the background', function () {
    installedPlugin();
    Queue::fake();

    Livewire::actingAs(pluginAdmin())->test('pages::plugins.index')->call('removePlugin', 'acme/swage-gitea');

    expect(SelfUpdate::sole()->plugin_operation)->toBe(['action' => 'remove', 'name' => 'acme/swage-gitea']);
    Queue::assertPushed(StartSelfUpdate::class);
});

test('plugin changes show in the update history', function () {
    SelfUpdate::factory()->create(['plugin_operation' => ['action' => 'remove', 'name' => 'acme/swage-gitea']]);

    $this->actingAs(pluginAdmin())->get(route('updates'))->assertSee('Remove acme/swage-gitea');
});

test('the system tab lists the providers source control plugins add, linking only those with settings to their config page', function () {
    installedPlugin();
    $directory = storage_path('framework/testing/vendor-'.uniqid().'/vendor/acme/swage-gitea/src');
    File::ensureDirectoryExists($directory);
    File::put($directory.'/GiteaProvider.php', '<?php class AcmeSwageGiteaProvider extends Tests\Fakes\FakeSourceControlProvider { public function __construct() { parent::__construct("gitea"); } public function name(): string { return "Gitea"; } }');
    require $directory.'/GiteaProvider.php';
    app(SourceControlProviders::class)->register(AcmeSwageGiteaProvider::class);

    expect(array_keys(app(SourceControlProviders::class)->providedBy('acme/swage-gitea')))->toBe(['gitea'])
        ->and(app(SourceControlProviders::class)->providedBy('acme/other'))->toBe([]);

    $this->actingAs(pluginAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertSee('Gitea')
        ->assertDontSee(route('source-control.configure', 'gitea'));

    File::deleteDirectory(dirname($directory, 4));
});

test('plugins are split into system, server and site tabs', function () {
    fakePluginIndex([
        ['name' => 'acme/swage-gitea', 'title' => 'Gitea', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-gitea.git'],
        ['name' => 'acme/swage-netdata', 'title' => 'Netdata', 'kind' => 'server-integration', 'repository' => 'https://github.com/acme/swage-netdata.git'],
        ['name' => 'acme/swage-owl', 'title' => 'Owl', 'kind' => 'site-integration', 'repository' => 'https://github.com/acme/swage-owl.git'],
    ]);
    Http::fake([PLUGIN_INDEX_URL => fn () => Http::response(['plugins' => $GLOBALS['fakePluginIndexEntries']])]);
    installedPlugin();
    $admin = pluginAdmin();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('acme/swage-gitea')
        ->assertSee('No source control plugins are installed')
        ->assertDontSee('acme/swage-netdata')
        ->assertDontSee('acme/swage-owl')
        ->assertSee(route('plugins', 'server'))
        ->assertSee(route('plugins', 'site'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'server'))
        ->assertOk()
        ->assertSee('acme/swage-netdata')
        ->assertSee('No server plugins installed.')
        ->assertDontSee('acme/swage-gitea')
        ->assertDontSee('Source control providers');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'site'))
        ->assertOk()
        ->assertSee('acme/swage-owl')
        ->assertDontSee('acme/swage-netdata');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/plugins/other')->assertNotFound();
});

test('each plugin kind belongs to a category', function () {
    expect(PluginKind::SourceControl->category())->toBe(PluginCategory::System)
        ->and(PluginKind::ServerIntegration->category())->toBe(PluginCategory::Server)
        ->and(PluginKind::SiteIntegration->category())->toBe(PluginCategory::Site);
});

test('plugin installs, updates and removals are listed on the activity tab, not the category tabs', function () {
    SelfUpdate::factory()->create(['plugin_operation' => ['action' => 'remove', 'name' => 'acme/swage-gitea']]);
    $admin = pluginAdmin();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertDontSee('Remove acme/swage-gitea')
        ->assertSee(route('plugins', 'activity'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'activity'))
        ->assertOk()
        ->assertSee('Remove acme/swage-gitea')
        ->assertDontSee('Available');
});

test('the activity tab says when no plugin has been changed yet', function () {
    $this->actingAs(pluginAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'activity'))
        ->assertOk()
        ->assertSee('No plugins have been installed, updated or removed yet.');
});

test('a plugin links to its repository\'s web page', function (string $repository, ?string $url) {
    $plugin = new Plugin('acme/swage-gitea', PluginKind::SourceControl, $repository, 'v1.0.0', PLUGIN_COMMIT, 'checkout', now()->toIso8601String());

    expect($plugin->repositoryUrl())->toBe($url);
})->with([
    'https' => ['https://github.com/acme/swage-gitea.git', 'https://github.com/acme/swage-gitea'],
    'https without .git' => ['https://gitlab.example.com/acme/swage-gitea', 'https://gitlab.example.com/acme/swage-gitea'],
    'scp-like ssh' => ['git@github.com:acme/swage-gitea.git', 'https://github.com/acme/swage-gitea'],
    'ssh url with a port' => ['ssh://git@git.example.com:2222/acme/swage-gitea.git', 'https://git.example.com/acme/swage-gitea'],
    'something else' => ['file:///srv/git/swage-gitea.git', null],
]);

test('plugin names link to their repositories instead of listing the git url', function () {
    fakePluginIndex([
        ['name' => 'acme/swage-gitea', 'title' => 'Gitea', 'kind' => 'source-control', 'repository' => 'https://github.com/acme/swage-gitea.git'],
        ['name' => 'acme/swage-forgejo', 'title' => 'Forgejo', 'kind' => 'source-control', 'repository' => 'git@github.com:acme/swage-forgejo.git'],
    ]);
    Http::fake([PLUGIN_INDEX_URL => fn () => Http::response(['plugins' => $GLOBALS['fakePluginIndexEntries']])]);
    installedPlugin();

    $this->actingAs(pluginAdmin())->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins'))
        ->assertOk()
        ->assertSee('href="https://github.com/acme/swage-gitea"', false)
        ->assertSee('href="https://github.com/acme/swage-forgejo"', false)
        ->assertDontSee('>https://github.com/acme/swage-gitea.git<', false)
        ->assertDontSee('Repository');
});
