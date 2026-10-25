<?php

use App\Enums\ConfigService;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Jobs\SyncDatabaseAccess;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerTuner;
use App\Provisioning\ServiceConfigFiles;
use App\Ssh\LogFiles;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function activeDatabaseServer(array $attributes = []): Server
{
    $server = Server::factory()->database()->upgraded()->create(['status' => ServerStatus::Active, ...$attributes]);

    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), $server->usesMysql(), false, $server->usesPostgres(), withPhp: false)->toArray()]);

    return $server;
}

test('a database server can be connected without php or redis', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::servers.create')
        ->set('type', 'database')
        ->set('name', 'db-1')
        ->set('ip_address', '203.0.113.20')
        ->set('private_ip_address', '10.0.0.5')
        ->set('database', 'postgres-pgvector')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->servers()->sole())
        ->type->toBe(ServerType::Database)
        ->private_ip_address->toBe('10.0.0.5')
        ->php_versions->toBeEmpty()
        ->default_php_version->toBeNull()
        ->has_redis->toBeFalse()
        ->redis_password->toBeNull()
        ->has_postgres->toBeTrue()
        ->has_pgvector->toBeTrue();
});

test('a database server must run a database', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('type', 'database')
        ->assertSet('database', 'mysql')
        ->set('database', 'none')
        ->set('name', 'db-1')
        ->set('ip_address', '203.0.113.20')
        ->call('save')
        ->assertHasErrors(['database']);
});

test('switching to a database server replaces no database with mysql', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('database', 'none')
        ->set('type', 'database')
        ->assertSet('database', 'mysql');
});

test('the private ip address must be a different, valid ip address', function (string $privateIp) {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('name', 'app-1')
        ->set('ip_address', '203.0.113.20')
        ->set('private_ip_address', $privateIp)
        ->call('save')
        ->assertHasErrors(['private_ip_address']);
})->with(['not-an-ip', '203.0.113.20']);

test('provisioning a database server skips the web stack and opens only ssh', function () {
    $server = Server::factory()->database()->postgres()->pending()->create(['private_ip_address' => '10.0.0.5']);

    $response = $this->post(route('provision.specs', $server->provision_token), ['cpu_cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 160, 'os_version' => '24.04']);

    $server->refresh();

    $response->assertOk()
        ->assertSee('apt-get install -yq postgresql', escape: false)
        ->assertSee("listen_addresses = '127.0.0.1,10.0.0.5'", escape: false)
        ->assertSee('shared_buffers = 2048MB', escape: false)
        ->assertSee('effective_cache_size = 6144MB', escape: false)
        ->assertSee("grep -m1 '# swage-database'", escape: false)
        ->assertSee('include_if_exists swage-hba.conf', escape: false)
        ->assertDontSee('nginx')
        ->assertDontSee('ppa:ondrej/php')
        ->assertDontSee('nodesource')
        ->assertDontSee('ufw allow 80/tcp')
        ->assertDontSee('redis-server');

    expect($server->tuning['php'])->toBeNull()
        ->and($server->tuning['nginx'])->toBeNull()
        ->and($server->tuning['postgres']['max_connections'])->toBe(500);
});

test('a database server without a private ip listens on every interface behind the firewall', function () {
    $server = activeDatabaseServer();

    $configFiles = ServiceConfigFiles::for($server);

    expect($configFiles->base(ConfigService::Mysql))->toContain('bind-address = *');
});

test('a database server with a private ip only listens on it and the loopback interface', function () {
    $server = activeDatabaseServer(['private_ip_address' => '10.0.0.5']);

    expect(ServiceConfigFiles::for($server)->base(ConfigService::Mysql))->toContain('bind-address = 127.0.0.1,10.0.0.5');
});

test('app servers keep their databases on the loopback interface', function () {
    $server = Server::factory()->create(['private_ip_address' => '10.0.0.9']);
    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), true, true)->toArray()]);

    expect(ServiceConfigFiles::for($server)->base(ConfigService::Mysql))->toContain('bind-address = 127.0.0.1');
});

test('databases get the memory php would have had on a database server', function () {
    $specs = new ServerSpecs(4, 8192, 160);
    $tuner = new ServerTuner;

    $dedicated = $tuner->tune($specs, true, false, withPhp: false);
    $both = $tuner->tune($specs, true, false, withPostgres: true, withPhp: false);
    $app = $tuner->tune($specs, true, true);

    expect($dedicated->php)->toBeNull()
        ->and($dedicated->mysql['innodb_buffer_pool_size_mb'])->toBeGreaterThan($app->mysql['innodb_buffer_pool_size_mb'])
        ->and($dedicated->mysql['innodb_buffer_pool_size_mb'] + $tuner->operatingSystemReserveMb($specs))->toBeLessThanOrEqual(8192)
        ->and($both->mysql['innodb_buffer_pool_size_mb'] + $both->postgres['shared_buffers_mb'] + $tuner->operatingSystemReserveMb($specs))->toBeLessThanOrEqual(8192)
        ->and(array_column($dedicated->memoryAllocation(8192), 'service'))->not->toContain('PHP-FPM');
});

test('a database server has no sites, nginx or php', function () {
    $server = activeDatabaseServer();

    $this->actingAs($server->user)->get(route('servers.sites', $server))->assertNotFound();

    $this->actingAs($server->user)->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee('Database server')
        ->assertDontSee(route('servers.sites', $server));

    expect(ConfigService::availableOn($server))->toBe([ConfigService::Mysql])
        ->and(collect(LogFiles::forServer($server))->map->location()->all())->not->toContain('/var/log/nginx/error.log');
});

test('every page of a database server renders', function () {
    $server = activeDatabaseServer(['has_postgres' => true]);
    $this->actingAs($server->user);

    foreach (['servers.show', 'servers.databases', 'servers.daemons', 'servers.scheduler', 'servers.config', 'servers.logs', 'servers.activity'] as $route) {
        $this->get(route($route, $server))->assertOk();
    }

    $this->get(route('dashboard'))->assertOk()->assertSee('MySQL &amp; PostgreSQL', escape: false);
});

test('allowing an address opens every engine\'s port to it in the firewall and postgres', function () {
    $shell = fakeShell();
    $server = activeDatabaseServer(['has_postgres' => true]);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('accessAddress', '198.51.100.7')
        ->call('allowAccess')
        ->assertHasNoErrors();

    expect($server->refresh()->databaseAccess())->toBe(['198.51.100.7']);

    expect($shell->lastScript())
        ->toContain("ufw allow from 198.51.100.7 to any port 3306 proto tcp comment 'swage-database'")
        ->toContain("ufw allow from 198.51.100.7 to any port 5432 proto tcp comment 'swage-database'")
        ->toContain('host all all 198.51.100.7/32 scram-sha-256')
        ->toContain('systemctl reload postgresql');
});

test('a private range can be allowed', function () {
    $shell = fakeShell();
    $server = activeDatabaseServer(['has_mysql' => false, 'has_postgres' => true]);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('accessAddress', '10.0.0.0/24')
        ->call('allowAccess')
        ->assertHasNoErrors();

    expect($shell->lastScript())->toContain('host all all 10.0.0.0/24 scram-sha-256')->not->toContain('3306');
});

test('access addresses are validated', function (string $address) {
    Queue::fake();
    $server = activeDatabaseServer(['database_access' => ['198.51.100.7']]);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('accessAddress', $address)
        ->call('allowAccess')
        ->assertHasErrors(['accessAddress']);

    Queue::assertNothingPushed();
})->with([
    'not an address' => ['db.example.com'],
    'shell injection' => ['1.2.3.4; reboot'],
    'everything' => ['0.0.0.0/0'],
    'too wide' => ['10.0.0.0/4'],
    'bad prefix' => ['10.0.0.0/33'],
    'already allowed' => ['198.51.100.7'],
]);

test('another server is allowed by its private ip when the database server listens on one', function () {
    fakeShell();
    $server = activeDatabaseServer(['private_ip_address' => '10.0.0.5']);
    $app = Server::factory()->for($server->user)->create(['private_ip_address' => '10.0.0.9']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('allowServer', $app->id);

    expect($server->refresh()->databaseAccess())->toBe(['10.0.0.9']);
});

test('another server is allowed by its public ip when the database server has no private ip', function () {
    fakeShell();
    $server = activeDatabaseServer();
    $app = Server::factory()->for($server->user)->create(['ip_address' => '198.51.100.9', 'private_ip_address' => '10.0.0.9']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('allowServer', $app->id);

    expect($server->refresh()->databaseAccess())->toBe(['198.51.100.9']);
});

test('a server without a private ip cannot be allowed onto a private network database server', function () {
    Queue::fake();
    $server = activeDatabaseServer(['private_ip_address' => '10.0.0.5']);
    $app = Server::factory()->for($server->user)->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('allowServer', $app->id)
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('another user\'s server cannot be allowed', function () {
    Queue::fake();
    $server = activeDatabaseServer();
    $stranger = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('allowServer', $stranger->id)
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('revoking an address removes its firewall rules', function () {
    $shell = fakeShell();
    $server = activeDatabaseServer(['database_access' => ['198.51.100.7', '198.51.100.8']]);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('revokeAccess', '198.51.100.7');

    expect($server->refresh()->databaseAccess())->toBe(['198.51.100.8']);
    expect($shell->lastScript())
        ->toContain('ufw --force delete "$rule"')
        ->toContain('ufw allow from 198.51.100.8')
        ->not->toContain('198.51.100.7');
});

test('app servers have no remote database access to manage', function () {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('accessAddress', '198.51.100.7')
        ->call('allowAccess')
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('an engine installed later on a database server is opened to the allowed addresses', function () {
    $shell = fakeShell()->respondTo('SWAGE_CPU_CORES', new ScriptResult(0, "SWAGE_CPU_CORES 2\nSWAGE_MEMORY_MB 4096\nSWAGE_DISK_GB 80"));
    $server = activeDatabaseServer(['database_access' => ['198.51.100.7']]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installDatabaseEngine', 'postgres');

    expect($shell->lastScript())
        ->toContain('ufw allow from 198.51.100.7 to any port 5432')
        ->toContain('host all all 198.51.100.7/32 scram-sha-256')
        ->toContain("listen_addresses = '*'")
        ->not->toContain('nginx');
});

test('sync jobs only run for the server they were queued for', function () {
    Queue::fake();
    $server = activeDatabaseServer();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('accessAddress', '198.51.100.7')
        ->call('allowAccess');

    Queue::assertPushed(SyncDatabaseAccess::class, fn (SyncDatabaseAccess $job) => $job->server->is($server));
});

test('tuning stops ubuntu\'s mysqld.cnf from overriding the bind address', function () {
    $server = Server::factory()->database()->pending()->create();

    $this->post(route('provision.specs', $server->provision_token), ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80, 'os_version' => '24.04'])
        ->assertSee("sed -i -E 's/^[[:space:]]*bind-address/# &/' /etc/mysql/mysql.conf.d/mysqld.cnf", escape: false)
        ->assertSee('bind-address = *', escape: false);
});
