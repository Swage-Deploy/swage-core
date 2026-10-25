<?php

use App\Enums\ConfigService;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Jobs\OptimizeServer;
use App\Jobs\RemoveSite;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Provisioning\ServerTuner;
use App\Ssh\ScriptResult;
use Livewire\Livewire;

function tunedServer(array $attributes = []): Server
{
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85], ...$attributes]);

    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres())->toArray()]);

    return $server;
}

test('a custom nginx config replaces the matching swage directives', function () {
    $shell = fakeShell();
    $server = tunedServer();

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server])
        ->set('override', "client_max_body_size 2g;\nproxy_read_timeout 300;")
        ->call('save')
        ->assertSet('failureOutput', null);

    $script = $shell->lastScript();

    expect($script)->toContain("cat > '/etc/nginx/swage/http.conf'")
        ->toContain("client_max_body_size 2g;\nproxy_read_timeout 300;")
        ->not->toContain('client_max_body_size 100m;')
        ->toContain('include /etc/nginx/swage/http.conf;')
        ->toContain('nginx -t')
        ->toContain('systemctl reload nginx')
        ->and($server->fresh()->configOverride(ConfigService::Nginx))->toBe("client_max_body_size 2g;\nproxy_read_timeout 300;")
        ->and($server->tasks()->sole()->name)->toBe('Applying custom nginx config');
});

test('php overrides are applied to every installed version', function () {
    $shell = fakeShell();
    $server = tunedServer();

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'php-fpm-ini'])
        ->assertSet('service', 'php-fpm-ini')
        ->set('override', 'memory_limit = 1G')
        ->call('save');

    expect($shell->lastScript())
        ->toContain("cat > '/etc/php/8.4/fpm/conf.d/99-swage-custom.ini'")
        ->toContain("cat > '/etc/php/8.5/fpm/conf.d/99-swage-custom.ini'")
        ->toContain('check_clean php-fpm8.4 -t')
        ->toContain('systemctl reload php8.5-fpm')
        ->not->toContain('memory_limit = 512M');
});

test('mysql overrides are placed in the mysqld section and restart mysql', function () {
    $shell = fakeShell();
    $server = tunedServer();

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'mysql'])
        ->set('override', 'max-connections = 500')
        ->call('save');

    expect($shell->lastScript())
        ->toContain("[mysqld]\nmax-connections = 500")
        ->not->toMatch('/^max_connections = \d+$/m')
        ->toContain('mysqld --validate-config')
        ->toContain('systemctl restart mysql')
        ->toContain('mysqladmin ping --silent');
});

test('a config the server rejects is not saved and its output is shown', function () {
    fakeShell()->respondTo('/etc/nginx/swage/http.conf', new ScriptResult(1, "nginx: [emerg] unknown directive \"bogus\"\nRestoring the previous configuration."));
    $server = tunedServer(['config_overrides' => ['nginx' => 'gzip off;']]);

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server])
        ->assertSet('override', 'gzip off;')
        ->set('override', 'bogus on;')
        ->call('save')
        ->assertSet('failureOutput', fn (string $output) => str_contains($output, 'unknown directive'))
        ->assertSee('Rejected by the server');

    expect($server->fresh()->configOverride(ConfigService::Nginx))->toBe('gzip off;');
});

test('clearing an override removes it', function () {
    fakeShell();
    $server = tunedServer(['config_overrides' => ['redis' => 'maxmemory 1gb', 'nginx' => 'gzip off;']]);

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'redis'])
        ->set('override', '')
        ->call('save');

    expect($server->fresh()->config_overrides)->toBe(['nginx' => 'gzip off;']);
});

test('services the server does not run cannot be selected', function () {
    $server = tunedServer(['has_mysql' => false, 'has_redis' => false]);

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'mysql'])
        ->assertSet('service', 'nginx')
        ->assertDontSee('MySQL');
});

test('the preview marks the swage lines a draft overrides', function () {
    $server = tunedServer();

    $component = Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'redis'])
        ->set('override', 'maxmemory 2gb');

    $overridden = collect($component->instance()->preview)->where('overridden', true)->pluck('line')->all();

    expect($overridden)->toBe(['maxmemory '.$server->tuning['redis']['maxmemory_mb'].'mb']);
});

test('only the server owner can change its config', function () {
    $shell = fakeShell();
    $server = tunedServer();

    $this->actingAs(User::factory()->create())->get(route('servers.config', $server))->assertForbidden();

    $shell->assertNothingRan();
});

test('re-optimizing keeps custom config', function () {
    $shell = fakeShell()->respondTo('nproc', new ScriptResult(0, "SWAGE_CPU_CORES 4\nSWAGE_MEMORY_MB 8192\nSWAGE_DISK_GB 160\n"));
    $server = tunedServer(['config_overrides' => ['php-fpm-pool' => 'pm.max_children = 7', 'nginx' => 'client_max_body_size 2g;']]);

    OptimizeServer::dispatchSync($server);

    expect($shell->lastScript())
        ->toContain("pm.max_children = 7\n")
        ->not->toMatch('/^pm\.max_children = (?!7$)\d+$/m')
        ->toContain('client_max_body_size 2g;')
        ->not->toContain('client_max_body_size 100m;');
});

test('custom site nginx replaces the matching directives in the site server block', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['status' => ResourceStatus::Installed]);

    Livewire::actingAs($site->server->user)
        ->test('pages::sites.nginx', ['server' => $site->server, 'site' => $site])
        ->set('override', "location / {\n    return 503;\n}")
        ->call('save')
        ->assertSet('failureOutput', null);

    expect($shell->lastScript())
        ->toContain("cat > '/etc/nginx/swage/sites/{$site->domain}.conf'")
        ->toContain("include /etc/nginx/swage/sites/{$site->domain}.conf;")
        ->not->toContain('try_files $uri $uri/ /index.php?$query_string;')
        ->and($site->fresh()->nginx_override)->toBe("location / {\n    return 503;\n}");
});

test('custom site nginx is kept when swage rewrites the site config', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['nginx_override' => 'client_max_body_size 1g;']);

    UpdateSiteConfiguration::dispatchSync($site);

    expect($shell->lastScript())->toContain("client_max_body_size 1g;\n");
});

test('custom site nginx can only be applied to an installed site', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['status' => ResourceStatus::Installing]);

    Livewire::actingAs($site->server->user)
        ->test('pages::sites.nginx', ['server' => $site->server, 'site' => $site])
        ->set('override', 'gzip off;')
        ->call('save');

    $shell->assertNothingRan();
    expect($site->fresh()->nginx_override)->toBeNull();
});

test('removing a site removes its custom nginx', function () {
    $shell = fakeShell();
    $site = Site::factory()->create();

    RemoveSite::dispatchSync($site);

    $shell->assertRan("/etc/nginx/swage/sites/{$site->domain}.conf");
});
