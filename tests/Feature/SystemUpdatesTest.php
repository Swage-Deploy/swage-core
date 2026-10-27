<?php

use App\Enums\PhpVersion;
use App\Jobs\CheckRebootRequired;
use App\Jobs\RebootServer;
use App\Models\Server;
use App\Provisioning\ServerTuner;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('new servers install security updates from ubuntu, the php ppa and nodesource without rebooting', function () {
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php85]]);
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres());

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))
        ->toContain('apt-get install -yq unattended-upgrades')
        ->toContain('APT::Periodic::Unattended-Upgrade "1";')
        ->toContain('"origin=LP-PPA-ondrej-php,codename=${distro_codename}";')
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
        ->not->toContain('LP-PPA-ondrej-php')
        ->not->toContain('deb.nodesource.com');
});

test('the panel host also installs updates from the php ppa, nodesource and cloudflared', function () {
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
