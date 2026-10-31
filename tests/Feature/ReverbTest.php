<?php

use App\Actions\Sites\SetOctane;
use App\Actions\Sites\SetReverb;
use App\Deployments\DeployScript;
use App\Enums\MemberRole;
use App\Jobs\ConfigureReverbEnvironment;
use App\Jobs\DeploySite;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Daemon;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Sites\SiteEnvironment;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('the reverb panel appears once the live release has laravel/reverb', function () {
    $shell = fakeShell()->respondTo('vendor/laravel/reverb/composer.json', new ScriptResult(exitCode: 0, output: "installed\n"));
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->assertDontSee('Laravel Reverb')
        ->call('checkReverb')
        ->assertSet('reverbInstalled', true)
        ->assertSee('Laravel Reverb')
        ->assertSee('Enable Reverb');

    $shell->assertRan("test -f '{$site->currentPath()}/vendor/laravel/reverb/composer.json'");
});

test('sites without laravel/reverb get no reverb panel', function () {
    fakeShell()->respondTo('vendor/laravel/reverb/composer.json', new ScriptResult(exitCode: 0, output: "missing\n"));
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->call('checkReverb')
        ->assertSet('reverbInstalled', false)
        ->assertDontSee('Laravel Reverb');
});

test('the check is remembered until the site is next deployed', function () {
    $shell = fakeShell()->respondTo('vendor/laravel/reverb/composer.json', new ScriptResult(exitCode: 0, output: "installed\n"));
    $site = Site::factory()->withRepository()->create();

    expect(SetReverb::installed($site, $shell))->toBeTrue();
    $shell->ran = [];

    expect(SetReverb::installed($site, $shell))->toBeTrue();
    $shell->assertNothingRan();

    Bus::fake();
    $deployment = Deployment::factory()->for($site)->create();
    (new DeploySite($deployment))->handle($shell);

    expect(Cache::has(SetReverb::packageCacheKey($site)))->toBeFalse();
});

test('an unreachable server is not remembered as having no reverb', function () {
    $shell = fakeShell()->respondTo('vendor/laravel/reverb/composer.json', new ScriptResult(exitCode: 255, output: 'Connection refused'));
    $site = Site::factory()->create();

    expect(SetReverb::installed($site, $shell))->toBeNull()
        ->and(Cache::has(SetReverb::packageCacheKey($site)))->toBeFalse();
});

test('enabling reverb starts its server, then sends traffic to it and points the app at it', function () {
    Bus::fake();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->call('toggleReverb');

    $site->refresh();
    $daemon = $site->server->daemons()->sole();

    expect($site->reverb)->toBeTrue()
        ->and($site->reverb_port)->toBe(SetReverb::FIRST_PORT)
        ->and($daemon->isReverb())->toBeTrue()
        ->and($daemon->user)->toBe($site->systemUser())
        ->and($daemon->command)->toContain("{$site->currentPath()}/artisan reverb:start --host=127.0.0.1 --port=8080");

    Bus::assertChained([InstallDaemon::class, UpdateSiteConfiguration::class, ConfigureReverbEnvironment::class]);
});

test('every site on a server gets a reverb port no other site uses, octane ports included', function () {
    Bus::fake();
    $first = Site::factory()->create(['reverb_port' => 8080]);
    $octane = Site::factory()->inPlace()->create(['server_id' => $first->server_id, 'octane_port' => 8081]);
    $kept = Site::factory()->create(['server_id' => $first->server_id, 'reverb' => false, 'reverb_port' => 8082]);
    $site = Site::factory()->create(['server_id' => $first->server_id]);
    $elsewhere = Site::factory()->create();

    app(SetReverb::class)->handle($site, true);
    app(SetReverb::class)->handle($elsewhere, true);

    expect($site->fresh()->reverb_port)->toBe(8083)
        ->and($elsewhere->fresh()->reverb_port)->toBe(8080);
});

test('ports that daemons added by hand are started with are skipped', function () {
    Bus::fake();
    $site = Site::factory()->create();
    Daemon::factory()->for($site->server)->create(['command' => 'php8.5 /home/app/current/artisan reverb:start --port=8080']);
    Daemon::factory()->for($site->server)->create(['command' => 'node server.js --port 8081']);

    app(SetReverb::class)->handle($site, true);

    expect($site->fresh()->reverb_port)->toBe(8082);
});

test('octane ports skip ports reverb uses', function () {
    Bus::fake();
    $reverb = Site::factory()->create(['reverb' => true, 'reverb_port' => SetOctane::FIRST_PORT]);
    $site = Site::factory()->inPlace()->create(['server_id' => $reverb->server_id]);

    app(SetOctane::class)->handle($site, true);

    expect($site->fresh()->octane_port)->toBe(SetOctane::FIRST_PORT + 1);
});

test('a site turning reverb back on keeps its port', function () {
    Bus::fake();
    $site = Site::factory()->create(['reverb_port' => 8090]);

    app(SetReverb::class)->handle($site, true);

    expect($site->fresh()->reverb_port)->toBe(8090);
});

test('disabling reverb stops sending traffic to it before stopping its server', function () {
    Bus::fake();
    $site = Site::factory()->create(['reverb' => true, 'reverb_port' => 8080]);
    $daemon = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'command' => 'php8.5 /home/x/current/artisan reverb:start --host=127.0.0.1 --port=8080']);

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->assertSee('Laravel Reverb')
        ->call('toggleReverb');

    expect($site->fresh())->reverb->toBeFalse()->reverb_port->toBe(8080)
        ->and($daemon->fresh()->status->value)->toBe('removing');

    Bus::assertChained([UpdateSiteConfiguration::class, RemoveDaemon::class]);
});

test('viewers cannot turn reverb on', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $viewer = User::factory()->create();
    $site->server->members()->attach($viewer->id, ['role' => MemberRole::Viewer->value]);

    Livewire::actingAs($viewer)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->call('toggleReverb');

    expect($site->fresh()->reverb)->toBeFalse();
    Bus::assertNothingDispatched();
});

test('nginx sends reverb paths to the site\'s reverb port', function () {
    $site = Site::factory()->create(['reverb' => true, 'reverb_port' => 8081]);

    $config = Script::render('sites.nginx', ['site' => $site, 'certificate' => null]);

    expect($config)
        ->toContain('location ~ ^/(app/[^/]+$|apps/) {')
        ->toContain('proxy_pass http://127.0.0.1:8081;')
        ->toContain('fastcgi_pass');

    expect(Script::render('sites.nginx', ['site' => Site::factory()->create(['reverb_port' => 8081]), 'certificate' => null]))
        ->not->toContain('127.0.0.1:8081');
});

test('the app is pointed at reverb through its own address, with the scheme of its APP_URL', function (string $appUrl, string $port, string $scheme) {
    $shell = fakeShell()->respondTo("cat '", new ScriptResult(exitCode: 0, output: "APP_NAME=Shop\nAPP_URL={$appUrl}\n"));
    $site = Site::factory()->create(['domain' => 'shop.example.com', 'reverb' => true, 'reverb_port' => 8082]);

    app()->call([new ConfigureReverbEnvironment($site), 'handle']);

    $script = $shell->lastScript();

    foreach ([
        'REVERB_HOST' => 'shop.example.com',
        'REVERB_PORT' => $port,
        'REVERB_SCHEME' => $scheme,
        'REVERB_SERVER_HOST' => '127.0.0.1',
        'REVERB_SERVER_PORT' => '8082',
    ] as $key => $value) {
        expect($script)->toContain(base64_encode(SiteEnvironment::line($key, $value)));
    }
})->with([
    'https' => ['https://shop.example.com', '443', 'https'],
    'http' => ['http://shop.example.com', '80', 'http'],
]);

test('deployments restart reverb along with the queue workers', function () {
    $site = Site::factory()->withRepository()->create(['reverb' => true, 'reverb_port' => 8080]);
    $daemon = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'command' => 'php8.5 artisan reverb:start --port=8080']);

    expect(DeployScript::render($site->fresh()))->toContain("swage_restart_daemon {$daemon->id}");
});
