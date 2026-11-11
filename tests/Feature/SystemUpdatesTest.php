<?php

use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Jobs\CheckRebootRequired;
use App\Jobs\RebootServer;
use App\Jobs\SwitchPhpRepository;
use App\Models\Server;
use App\Provisioning\ServerTuner;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('new servers install security updates from ubuntu, packages.sury.org and nodesource without rebooting', function () {
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php85]]);
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres());

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))
        ->toContain('apt-get install -yq unattended-upgrades')
        ->toContain('APT::Periodic::Unattended-Upgrade "1";')
        ->toContain('"origin=deb.sury.org,codename=${distro_codename}";')
        ->not->toContain('LP-PPA-ondrej-php')
        ->toContain('"site=deb.nodesource.com";')
        ->toContain('Unattended-Upgrade::Automatic-Reboot "false";')
        ->toContain('systemctl enable --now unattended-upgrades');
});

test('database servers only install security updates from ubuntu, since they have no php or node.js', function () {
    $server = Server::factory()->database()->create();
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres(), $server->isAppServer());

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))
        ->toContain('apt-get install -yq unattended-upgrades')
        ->toContain('Unattended-Upgrade::Automatic-Reboot "false";')
        ->not->toContain('deb.sury.org')
        ->not->toContain('deb.nodesource.com');
});

test('the panel host also installs updates from the php repository, nodesource and cloudflared', function () {
    $migration = File::get(resource_path('scripts/host/migrations/2026_10_21_000018_unattended_upgrade_origins.sh'));

    expect($migration)
        ->toContain('"origin=LP-PPA-ondrej-php,codename=\${distro_codename}";')
        ->toContain('"site=deb.nodesource.com";')
        ->toContain('"origin=cloudflared";')
        ->toContain('Unattended-Upgrade::Automatic-Reboot "false";');
});

test('a server that needs a reboot records when it was first noticed and which packages asked for it', function () {
    $shell = fakeShell()->respondTo('/var/run/reboot-required', new ScriptResult(0, "SWAGE_REBOOT_REQUIRED\nlinux-image-6.8.0-90-generic\nlibc6\n"));
    $server = Server::factory()->create(['reboot_required_at' => now()->subDay()]);

    CheckRebootRequired::dispatchSync($server);

    expect($server->fresh())
        ->reboot_required_at->toDateString()->toBe(now()->subDay()->toDateString())
        ->reboot_required_packages->toBe(['linux-image-6.8.0-90-generic', 'libc6']);
    expect($server->tasks()->count())->toBe(0);
    $shell->assertRan('/var/run/reboot-required');
});

test('the reboot notice clears once the server no longer needs one', function () {
    fakeShell();
    $server = Server::factory()->create(['reboot_required_at' => now(), 'reboot_required_packages' => ['libc6']]);

    CheckRebootRequired::dispatchSync($server);

    expect($server->fresh())->reboot_required_at->toBeNull()->reboot_required_packages->toBeNull();
});

test('an unreachable server keeps what was last read', function () {
    fakeShell()->respondTo('/var/run/reboot-required', new ScriptResult(255, 'Connection refused'));
    $server = Server::factory()->create(['reboot_required_at' => now(), 'reboot_required_packages' => ['libc6']]);

    CheckRebootRequired::dispatchSync($server);

    expect($server->fresh()->reboot_required_packages)->toBe(['libc6']);
});

test('the hourly check only looks at active servers', function () {
    Queue::fake();
    $active = Server::factory()->create();
    Server::factory()->provisioning()->create();

    $this->artisan('swage:check-reboots')->assertSuccessful();

    Queue::assertPushed(CheckRebootRequired::class, 1);
    Queue::assertPushed(CheckRebootRequired::class, fn (CheckRebootRequired $job): bool => $job->server->is($active));
});

test('the overview shows when a reboot is needed and reboots the server', function () {
    Queue::fake();
    $server = Server::factory()->create(['reboot_required_at' => now(), 'reboot_required_packages' => ['linux-image-6.8.0-90-generic']]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('Reboot required')
        ->assertSee('linux-image-6.8.0-90-generic')
        ->call('reboot');

    Queue::assertPushed(RebootServer::class, fn (RebootServer $job): bool => $job->server->is($server));
});

test('the overview does not mention a reboot when none is needed', function () {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertDontSee('Reboot required');
});

test('only the owner can reboot a server', function () {
    Queue::fake();
    $server = Server::factory()->create(['reboot_required_at' => now()]);
    $other = Server::factory()->create();

    Livewire::actingAs($other->user)->test('pages::servers.show', ['server' => $server])->assertForbidden();

    Queue::assertNothingPushed();
});

test('rebooting schedules the reboot so the ssh session ends cleanly, then clears the notice', function () {
    $shell = fakeShell();
    $server = Server::factory()->create(['reboot_required_at' => now(), 'reboot_required_packages' => ['libc6']]);

    RebootServer::dispatchSync($server);

    $shell->assertRan('systemd-run --quiet --on-active=5');
    expect($server->fresh()->reboot_required_at)->toBeNull()
        ->and($server->tasks()->sole()->name)->toBe('Rebooting the server');
});

test('new app servers install php from packages.sury.org rather than the retired ppa', function () {
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php85]]);
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres());

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))
        ->toContain('https://packages.sury.org/debsuryorg-archive-keyring.deb')
        ->toContain('https://packages.sury.org/php/ ${VERSION_CODENAME} main')
        ->not->toContain('ppa:ondrej/php');
});

test('existing app servers are moved to packages.sury.org, with their php security updates', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();

    SwitchPhpRepository::dispatchSync($server);

    $shell->assertRan('rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*');
    $shell->assertRan('https://packages.sury.org/php/');
    $shell->assertRan('s/"origin=LP-PPA-ondrej-php,/"origin=deb.sury.org,/');

    expect($server->tasks()->first())
        ->name->toBe('Moving PHP to packages.sury.org')
        ->status->toBe(TaskStatus::Finished);
});

test('updating swage moves every active app server to packages.sury.org once', function () {
    Queue::fake();
    $app = Server::factory()->create();
    $database = Server::factory()->database()->create();
    $provisioning = Server::factory()->create(['status' => ServerStatus::Provisioning]);

    (require database_path('migrations/2026_10_31_235400_switch_php_repository.php'))->up();

    Queue::assertPushed(SwitchPhpRepository::class, 1);
    Queue::assertPushed(SwitchPhpRepository::class, fn (SwitchPhpRepository $job): bool => $job->server->is($app));
});

test('the panel host moves to packages.sury.org too', function () {
    $migration = File::get(resource_path('scripts/host/migrations/2026_11_11_000023_php_from_sury.sh'));
    $lib = File::get(resource_path('scripts/host/lib.sh'));

    expect($migration)->toContain('php_repository')->toContain('"origin=deb.sury.org,')
        ->and(File::get(resource_path('scripts/host/migrations/2026_10_14_000002_php.sh')))->toContain('php_repository')->not->toContain('ppa:ondrej/php')
        ->and($lib)->toContain('https://packages.sury.org/php/ $codename main');
});
