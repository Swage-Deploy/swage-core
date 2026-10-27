<?php

use App\Actions\Sites\SetOctane;
use App\Deployments\DeployScript;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Daemon;
use App\Models\Server;
use App\Models\Site;
use App\Ssh\Script;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('octane cannot be enabled on a zero downtime site from the settings page', function () {
    Bus::fake();
    $site = Site::factory()->withRepository()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->assertDontSee('Serve with Octane')
        ->set('octane', true)
        ->assertHasErrors(['octane'])
        ->assertSet('octane', false);

    expect($site->fresh()->octane)->toBeFalse();
    Bus::assertNothingDispatched();
});

test('octane can never be saved on a zero downtime site', function () {
    $site = Site::factory()->create();

    expect(fn () => $site->update(['octane' => true]))->toThrow(LogicException::class);
    expect(fn () => Site::factory()->create(['octane' => true]))->toThrow(LogicException::class);
    expect($site->fresh()->octane)->toBeFalse();
});

test('the deployment mode cannot change after a site is created', function () {
    $site = Site::factory()->inPlace()->create();

    expect(fn () => $site->update(['zero_downtime' => true]))->toThrow(LogicException::class);
    expect($site->fresh()->zero_downtime)->toBeFalse();
});

test('sites can be created without zero downtime deployments', function () {
    Bus::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('zero_downtime', false)
        ->call('create')
        ->assertHasNoErrors();

    expect($server->sites()->sole()->zero_downtime)->toBeFalse();
});

test('new sites use zero downtime deployments by default', function () {
    Bus::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->call('create');

    expect($server->sites()->sole()->zero_downtime)->toBeTrue();
});

test('enabling octane starts a tuned frankenphp server before switching nginx', function () {
    Bus::fake();
    $server = Server::factory()->create(['cpu_cores' => 4, 'memory_mb' => 8192]);
    $site = Site::factory()->inPlace()->for($server)->create();

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->set('octane', true)
        ->assertHasNoErrors();

    $site->refresh();
    $daemon = $site->daemons()->sole();

    expect($site->octane)->toBeTrue()
        ->and($site->octane_port)->toBe(SetOctane::FIRST_PORT)
        ->and($daemon->isOctane())->toBeTrue()
        ->and($daemon->command)->toContain('--server=frankenphp')
        ->and($daemon->command)->toContain('--port=8000')
        ->and($daemon->command)->toContain('--workers=4');

    Bus::assertChained([InstallDaemon::class, UpdateSiteConfiguration::class]);
});

test('each octane site on a server gets its own port', function () {
    Bus::fake();
    $server = Server::factory()->create();
    $first = Site::factory()->inPlace()->for($server)->create();
    $second = Site::factory()->inPlace()->for($server)->create();

    app(SetOctane::class)->handle($first, true);
    app(SetOctane::class)->handle($second, true);

    expect($first->fresh()->octane_port)->toBe(8000)
        ->and($second->fresh()->octane_port)->toBe(8001);
});

test('disabling octane switches nginx back to php-fpm before stopping the server', function () {
    Bus::fake();
    $site = Site::factory()->inPlace()->create(['octane' => true, 'octane_port' => 8000]);
    $daemon = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'command' => 'php8.5 artisan octane:start --server=frankenphp']);

    app(SetOctane::class)->handle($site, false);

    expect($site->fresh()->octane)->toBeFalse()
        ->and($daemon->fresh()->status->value)->toBe('removing');

    Bus::assertChained([UpdateSiteConfiguration::class, RemoveDaemon::class]);
});

test('octane sites are proxied by nginx', function () {
    $site = Site::factory()->inPlace()->create(['octane' => true, 'octane_port' => 8003]);

    $config = Script::render('sites.nginx', ['site' => $site, 'certificate' => null]);

    expect($config)
        ->toContain('try_files $uri @octane;')
        ->toContain('proxy_pass http://127.0.0.1:8003;')
        ->not->toContain('fastcgi_pass');
});

test('in place deployments pull into the live directory', function () {
    $site = Site::factory()->inPlace()->withRepository()->create();

    $script = DeployScript::render($site);

    expect($script)
        ->toContain('export SWAGE_RELEASE_DIRECTORY="$SWAGE_SITE_PATH/current"')
        ->toContain('reset --hard FETCH_HEAD')
        ->toContain("sudo -n /usr/local/sbin/swage-reload-php '8.5'")
        ->not->toContain('SWAGE_RELEASE_NAME')
        ->not->toContain('current-next');
});

test('octane deployments reload workers gracefully and leave the octane server out of queue restarts', function () {
    $site = Site::factory()->inPlace()->withRepository()->create(['octane' => true, 'octane_port' => 8000]);
    $octane = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'command' => 'php8.5 artisan octane:start --server=frankenphp']);
    $worker = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'command' => 'php8.5 artisan queue:work']);

    $script = DeployScript::render($site);

    expect($script)
        ->toContain('artisan" octane:reload')
        ->toContain("swage_restart_daemon {$worker->id}\n")
        ->not->toContain("swage_restart_daemon {$octane->id}\n")
        ->not->toContain('; swage_reload_php )');
});
