<?php

use App\Enums\DeploymentStatus;
use App\Jobs\StartSelfUpdate;
use App\Models\SelfUpdate;
use App\Models\User;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const CURRENT_COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const LATEST_COMMIT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    $this->root = storage_path('framework/testing/self-update-'.uniqid());
    $this->release = $this->root.'/releases/20260101000000';

    File::ensureDirectoryExists($this->release);
    File::ensureDirectoryExists($this->root.'/shared/storage');
    File::put($this->root.'/shared/.env', 'APP_ENV=production');
    File::put($this->root.'/swage-host-migrate', '');
    config(['swage.self_update.host_migrator' => $this->root.'/swage-host-migrate', 'swage.release_signers' => null]);
    symlink($this->release, $this->root.'/current');

    $this->app->instance(SelfUpdater::class, new SelfUpdater($this->release));

    Sleep::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeRepository(array $overrides = [], bool $healthy = true): void
{
    Http::fake(['*/up' => $healthy ? Http::response('OK') : Http::response('Server Error', 500)]);

    Process::fake([
        "'git' 'rev-parse' 'HEAD'" => fn (PendingProcess $process) => Process::result(
            str_contains($process->path, '20260101000000') ? CURRENT_COMMIT : LATEST_COMMIT,
        ),
        "'git' 'ls-remote' *" => LATEST_COMMIT."\trefs/heads/main",
        "'git' 'remote' 'get-url' 'origin'" => 'git@github.com:acme/swage.git',
        "'git' '-c' 'advice.detachedHead=false' 'clone' *" => function (PendingProcess $process) {
            File::ensureDirectoryExists(end($process->command).'/storage');

            return Process::result('Cloned');
        },
        ...$overrides,
        '*' => Process::result(),
    ]);
}

/**
 * Give the running release a release key, so it only updates to signed version tags, and fake a repository whose
 * newest version is v2.0.0 at LATEST_COMMIT.
 */
function fakeSignedRepository(string $root, array $overrides = []): void
{
    File::put($root.'/release-signers', 'releases@example.com namespaces="git" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample');
    config(['swage.release_signers' => $root.'/release-signers']);

    fakeRepository([
        "'git' 'ls-remote' *" => LATEST_COMMIT."\trefs/heads/main\n"
            ."eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\trefs/tags/v2.0.0\n"
            .LATEST_COMMIT."\trefs/tags/v2.0.0^{}",
        "'git' '-c' 'gpg.format=ssh' *" => Process::result(errorOutput: 'Good "git" signature for releases@example.com'),
        "'git' 'cat-file' 'tag' 'refs/tags/v2.0.0'" => 'object '.LATEST_COMMIT."\ntype commit\ntag v2.0.0\ntagger Swage\n\nv2.0.0\n",
        ...$overrides,
    ]);
}

function currentRelease(string $root): string
{
    return realpath($root.'/current');
}

test('swage updates to the latest commit and activates the new release', function () {
    fakeRepository();

    $this->artisan('swage:update')->assertSuccessful();

    $update = SelfUpdate::sole();

    expect($update)
        ->status->toBe(DeploymentStatus::Finished)
        ->from_commit->toBe(CURRENT_COMMIT)
        ->to_commit->toBe(LATEST_COMMIT);

    $newRelease = currentRelease($this->root);

    expect($newRelease)->not->toBe(realpath($this->release))
        ->and(is_link($newRelease.'/.env'))->toBeTrue()
        ->and(is_link($newRelease.'/storage'))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['php', 'artisan', 'migrate', '--force'] && $process->path === $newRelease);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['sudo', '-n', 'service', 'php8.5-fpm', 'reload']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['php', 'artisan', 'queue:restart']);
});

test('host migrations for the new commit run before the release goes live', function () {
    $ran = [];

    fakeRepository([
        "'sudo' '-n' '*swage-host-migrate' *" => function (PendingProcess $process) use (&$ran) {
            $ran[] = $process->command;

            return Process::result('Host is up to date.');
        },
        "'php' 'artisan' 'migrate' '--force'" => function () use (&$ran) {
            $ran[] = 'app migrations';

            return Process::result();
        },
    ]);

    $this->artisan('swage:update')->assertSuccessful();

    expect($ran)->toBe([
        ['sudo', '-n', $this->root.'/swage-host-migrate', LATEST_COMMIT],
        'app migrations',
    ]);
});

test('a failed host migration stops the update before anything goes live', function () {
    fakeRepository(["'sudo' '-n' '*swage-host-migrate' *" => Process::result(errorOutput: 'fail2ban failed to start', exitCode: 1)]);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->output)->toContain('fail2ban failed to start')
        ->and(currentRelease($this->root))->toBe(realpath($this->release))
        ->and(File::directories($this->root.'/releases'))->toHaveCount(1);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'migrate');
});

test('installs from before host migrations are told to run the adoption script', function () {
    fakeRepository();
    config(['swage.self_update.host_migrator' => $this->root.'/missing']);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->output)->toContain('adopt-swage.sh')
        ->and(currentRelease($this->root))->toBe(realpath($this->release));
});

test('nothing is rebuilt when swage is already up to date', function () {
    fakeRepository(["'git' 'ls-remote' *" => CURRENT_COMMIT."\trefs/heads/main"]);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole()->output)->toContain('Already up to date')
        ->and(currentRelease($this->root))->toBe(realpath($this->release));

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[3] ?? null) === 'clone');
});

test('a failed build leaves the running release untouched', function () {
    fakeRepository(["'npm' 'run' 'build'" => Process::result(errorOutput: 'vite exploded', exitCode: 1)]);

    $this->artisan('swage:update')->assertFailed();

    $update = SelfUpdate::sole();

    expect($update->status)->toBe(DeploymentStatus::Failed)
        ->and($update->output)->toContain('vite exploded')
        ->and(currentRelease($this->root))->toBe(realpath($this->release))
        ->and(File::directories($this->root.'/releases'))->toHaveCount(1);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'migrate');
});

test('a release that fails its health check is rolled back', function () {
    fakeRepository(healthy: false);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('Rolled back');

    expect(currentRelease($this->root))->toBe(realpath($this->release))
        ->and(File::directories($this->root.'/releases'))->toHaveCount(1);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'queue:restart');
});

test('old releases are pruned', function () {
    config(['swage.self_update.releases_to_keep' => 2]);
    File::ensureDirectoryExists($this->root.'/releases/20250101000000');
    File::ensureDirectoryExists($this->root.'/releases/20250601000000');
    fakeRepository();

    $this->artisan('swage:update')->assertSuccessful();

    expect(collect(File::directories($this->root.'/releases'))->map(fn ($path) => basename($path))->all())
        ->toBe([basename($this->release), basename(currentRelease($this->root))]);
});

test('only one update runs at a time', function () {
    fakeRepository();
    cache()->lock('swage-self-update', 60)->get();

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->output)->toContain('Another update is already running');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[3] ?? null) === 'clone');
});

test('installs without the release layout cannot self update', function () {
    $this->app->instance(SelfUpdater::class, new SelfUpdater(base_path()));

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::count())->toBe(0);
});

test('admins can start an update from the dashboard', function () {
    Queue::fake();
    fakeRepository();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::updates')
        ->call('startUpdate')
        ->assertSet('viewingUpdateId', SelfUpdate::sole()->id);

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Pending)
        ->user_id->toBe($admin->id);

    Queue::assertPushed(StartSelfUpdate::class, fn (StartSelfUpdate $job) => $job->update->is(SelfUpdate::sole()));
    Process::assertDidntRun(fn (PendingProcess $process) => is_string($process->command) && str_starts_with($process->command, 'setsid nohup '));
});

test('the queue worker starts dashboard updates in a detached process', function () {
    fakeRepository();
    $update = SelfUpdate::factory()->create(['status' => DeploymentStatus::Pending]);

    StartSelfUpdate::dispatchSync($update);

    Process::assertRan(fn (PendingProcess $process) => is_string($process->command) && str_starts_with($process->command, 'setsid nohup ')
        && str_contains($process->command, "'swage:update' '--update={$update->id}'"));
});

test('a second update cannot start while one is running', function () {
    fakeRepository();
    SelfUpdate::factory()->create(['status' => DeploymentStatus::Running]);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::updates')->call('startUpdate');

    expect(SelfUpdate::count())->toBe(1);
});

test('the updates page is only available to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('updates'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('updates'))->assertOk();
});

test('a release whose php-fpm reload fails is rolled back', function () {
    $reloads = 0;

    fakeRepository([
        "'sudo' '-n' 'service' *" => function () use (&$reloads) {
            return ++$reloads === 1 ? Process::result(exitCode: 1, errorOutput: 'sudo: a password is required') : Process::result();
        },
    ]);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('Reloading PHP-FPM failed');

    expect(currentRelease($this->root))->toBe(realpath($this->release))
        ->and(File::directories($this->root.'/releases'))->toHaveCount(1)
        ->and($reloads)->toBe(2);
});

test('a release that cannot boot stops before migrations change the database', function () {
    fakeRepository([
        "'php' 'artisan' 'optimize'" => Process::result(exitCode: 1, errorOutput: 'ParseError: syntax error'),
    ]);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->status)->toBe(DeploymentStatus::Failed)
        ->and(currentRelease($this->root))->toBe(realpath($this->release));

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'migrate');
});

test('the updates page says when the panel host needs a reboot', function () {
    fakeRepository();
    $admin = User::factory()->admin()->create();
    $flag = $this->root.'/reboot-required';
    config(['swage.self_update.reboot_required_file' => $flag]);

    Livewire::actingAs($admin)->test('pages::updates')->assertDontSee('The panel host needs a reboot');

    File::put($flag, '*** System restart required ***');

    Livewire::actingAs($admin)->test('pages::updates')
        ->assertSee('The panel host needs a reboot')
        ->assertSee('Reboot now')
        ->assertDontSee('SSH in as root');
});

test('administrators reboot the panel host through the root reboot command', function () {
    fakeRepository(["'sudo' '-n' '/usr/local/sbin/swage-reboot'" => Process::result('Rebooting in one minute.')]);
    config(['swage.self_update.reboot_required_file' => $flag = $this->root.'/reboot-required']);
    File::put($flag, '*** System restart required ***');

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::updates')
        ->call('reboot')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'], 'reboots in a minute'));

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['sudo', '-n', '/usr/local/sbin/swage-reboot']);
});

test('the panel host is not rebooted when it does not need it or an update is running', function () {
    fakeRepository();
    config(['swage.self_update.reboot_required_file' => $flag = $this->root.'/reboot-required']);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::updates')->call('reboot');

    File::put($flag, '*** System restart required ***');
    SelfUpdate::factory()->create(['status' => DeploymentStatus::Running]);

    Livewire::actingAs($admin)->test('pages::updates')->call('reboot');

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'sudo');
});

test('a host that predates the reboot command says to update swage first', function () {
    fakeRepository(["'sudo' '-n' '/usr/local/sbin/swage-reboot'" => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);
    config(['swage.self_update.reboot_required_file' => $flag = $this->root.'/reboot-required']);
    File::put($flag, '*** System restart required ***');

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::updates')
        ->call('reboot')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'], 'Update Swage first'));
});

test('only administrators reboot the panel host', function () {
    fakeRepository();

    Livewire::actingAs(User::factory()->create())->test('pages::updates')->assertForbidden();
});

test('the reboot host migration only lets swage run a reboot command that checks a reboot is required', function () {
    $migration = File::get(resource_path('scripts/host/migrations/2026_10_23_000019_panel_reboot.sh'));

    expect($migration)
        ->toContain('$APP_USER ALL=(root) NOPASSWD: /usr/local/sbin/swage-reboot')
        ->toContain('if [ ! -f /var/run/reboot-required ]; then')
        ->toContain('shutdown -r +1')
        ->toContain('visudo -cf /etc/sudoers.d/swage-reboot');
});

/**
 * The repository's refs: main at LATEST_COMMIT and version tags up to v1.2.0, an annotated tag pointing to TAGGED_COMMIT.
 */
const TAGGED_COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';

function taggedRefs(): string
{
    return LATEST_COMMIT."\trefs/heads/main\n"
        ."9999999999999999999999999999999999999999\trefs/tags/v1.1.0\n"
        ."dddddddddddddddddddddddddddddddddddddddd\trefs/tags/v1.2.0\n"
        .TAGGED_COMMIT."\trefs/tags/v1.2.0^{}\n"
        ."eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\trefs/tags/v2.0.0-beta\n";
}

test('swage updates to its newest version tag rather than the newest commit on the branch', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'rev-parse' 'HEAD'" => fn (PendingProcess $process) => Process::result(str_contains($process->path, '20260101000000') ? CURRENT_COMMIT : TAGGED_COMMIT),
    ]);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Finished)
        ->to_commit->toBe(TAGGED_COMMIT)
        ->to_version->toBe('v1.2.0')
        ->output->toContain('Swage is now running v1.2.0.');

    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 8) === ['git', '-c', 'advice.detachedHead=false', 'clone', '--depth', '1', '--branch', 'v1.2.0']);
});

test('swage follows its branch while the repository has no version tags', function () {
    fakeRepository();

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole())->to_commit->toBe(LATEST_COMMIT)->to_version->toBeNull();

    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 8) === ['git', '-c', 'advice.detachedHead=false', 'clone', '--depth', '1', '--branch', 'main']);
});

test('nothing is rebuilt when swage already runs the newest version', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'rev-parse' 'HEAD'" => TAGGED_COMMIT,
        "'git' 'describe' *" => 'v1.2.0',
    ]);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole()->output)->toContain('Already up to date at v1.2.0.')
        ->and(currentRelease($this->root))->toBe(realpath($this->release));
});

test('swage never moves back to an older version', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'describe' *" => 'v1.3.0',
    ]);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole()->output)->toContain('Already up to date at v1.3.0.');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[3] ?? null) === 'clone');
});

test('the updates page shows the running and newest versions', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'describe' *" => 'v1.1.0',
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::updates')
        ->assertSee('v1.1.0')
        ->call('check')
        ->assertSet('latest', ['ref' => 'v1.2.0', 'commit' => TAGGED_COMMIT, 'version' => 'v1.2.0'])
        ->assertSee('Latest version')
        ->assertSee('Update available');
});

test('each release compiles its own views, so a release never runs a livewire component another compiled', function () {
    $compiled = require base_path('config/view.php');

    expect($compiled['compiled'])->toStartWith(base_path())
        ->not->toStartWith(storage_path());
});

test('the updates page shows versions, never commits', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'rev-parse' 'HEAD'" => '9999999999999999999999999999999999999999',
    ]);
    SelfUpdate::factory()->create(['from_commit' => CURRENT_COMMIT, 'from_version' => 'v1.0.0', 'to_commit' => TAGGED_COMMIT, 'to_version' => 'v1.2.0']);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::updates')
        ->assertSee('v1.0.0 → v1.2.0')
        ->assertDontSee(substr(TAGGED_COMMIT, 0, 7))
        ->call('check')
        ->assertSet('currentVersion', 'v1.1.0')
        ->assertSee('v1.1.0')
        ->assertSee('v1.2.0')
        ->assertDontSee(substr(TAGGED_COMMIT, 0, 7))
        ->assertDontSee('9999999');
});

test('an update records the version it started from, even for a release cloned before its commit was tagged', function () {
    fakeRepository([
        "'git' 'ls-remote' *" => taggedRefs(),
        "'git' 'rev-parse' 'HEAD'" => fn (PendingProcess $process) => Process::result(str_contains($process->path, '20260101000000') ? '9999999999999999999999999999999999999999' : TAGGED_COMMIT),
    ]);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole())->from_version->toBe('v1.1.0')->to_version->toBe('v1.2.0');
});

test('once swage has a release key, it updates only to a version tag signed with it', function () {
    fakeSignedRepository($this->root);

    $this->artisan('swage:update')->assertSuccessful();

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Finished)
        ->to_version->toBe('v2.0.0')
        ->output->toContain('Checking the signature of v2.0.0')
        ->output->toContain('Signed with a Swage release key.');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', '-c', 'gpg.format=ssh', '-c', 'gpg.ssh.allowedSignersFile='.$this->root.'/release-signers', 'verify-tag', 'refs/tags/v2.0.0',
    ] && $process->path === currentRelease($this->root));
});

test('a version tag that is not signed with a release key is not installed', function () {
    fakeSignedRepository($this->root, [
        "'git' '-c' 'gpg.format=ssh' *" => Process::result(errorOutput: 'error: no signature found', exitCode: 1),
    ]);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('v2.0.0 is not signed by a Swage release key');

    expect(currentRelease($this->root))->toBe(realpath($this->release))
        ->and(File::directories($this->root.'/releases'))->toHaveCount(1);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'composer');
});

test('a signed tag that points elsewhere than the commit that was cloned is not installed', function () {
    fakeSignedRepository($this->root, [
        "'git' 'cat-file' 'tag' 'refs/tags/v2.0.0'" => "object 9999999999999999999999999999999999999999\ntype commit\ntag v2.0.0\n\nv2.0.0\n",
    ]);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->output)->toContain('does not point to the commit that was cloned')
        ->and(currentRelease($this->root))->toBe(realpath($this->release));
});

test('once swage has a release key, it no longer follows the branch of a repository without version tags', function () {
    fakeRepository();
    File::put($this->root.'/release-signers', 'releases@example.com namespaces="git" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample');
    config(['swage.release_signers' => $this->root.'/release-signers']);

    $this->artisan('swage:update')->assertFailed();

    expect(SelfUpdate::sole()->output)->toContain('Swage only updates to version tags signed with a Swage release key')
        ->and(currentRelease($this->root))->toBe(realpath($this->release));
});
