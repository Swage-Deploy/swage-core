<?php

use App\Enums\ConfigService;
use App\Enums\DatabaseEngine;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Enums\ServerStatus;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerTuner;
use App\Sites\SiteEnvironment;
use App\Ssh\LogFiles;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function tunedPostgresServer(): Server
{
    $server = Server::factory()->pgvector()->create();

    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), false, true, withPostgres: true)->toArray()]);

    return $server;
}

test('a server is connected with one database engine', function (string $database, bool $mysql, bool $postgres, bool $pgvector) {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::servers.create')
        ->set('name', 'vectors-1')
        ->set('ip_address', '203.0.113.10')
        ->set('database', $database)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->servers()->sole())
        ->has_mysql->toBe($mysql)
        ->has_postgres->toBe($postgres)
        ->has_pgvector->toBe($pgvector);
})->with([
    'mysql' => ['mysql', true, false, false],
    'postgres' => ['postgres', false, true, false],
    'postgres with pgvector' => ['postgres-pgvector', false, true, true],
    'none' => ['none', false, false, false],
]);

test('an unknown database type is rejected', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('name', 'db-1')
        ->set('ip_address', '203.0.113.10')
        ->set('database', 'mongodb')
        ->call('save')
        ->assertHasErrors(['database']);
});

test('provisioning installs postgres with pgvector, tuned to the hardware, instead of mysql', function () {
    $server = Server::factory()->pgvector()->pending()->create();

    $response = $this->post(route('provision.specs', $server->provision_token), [
        'cpu_cores' => 2,
        'memory_mb' => 4096,
        'disk_gb' => 80,
        'os_version' => '24.04',
        'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample',
    ]);

    $server->refresh();

    $response->assertOk()
        ->assertSee('apt-get install -yq postgresql postgresql-16-pgvector', escape: false)
        ->assertSee("ALTER ROLE \"swage\" WITH LOGIN SUPERUSER PASSWORD '{$server->database_password}';", escape: false)
        ->assertSee('/etc/postgresql/16/main/conf.d/99-swage.conf', escape: false)
        ->assertSee('shared_buffers = 1024MB', escape: false)
        ->assertSee('systemctl restart postgresql', escape: false)
        ->assertDontSee('mysql-server')
        ->assertDontSee('innodb_buffer_pool_size');

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->tuning['mysql'])->toBeNull()
        ->and($server->tuning['postgres']['shared_buffers_mb'])->toBe(1024);
});

test('plain postgres does not install pgvector', function () {
    $server = Server::factory()->postgres()->pending()->create();

    $this->post(route('provision.specs', $server->provision_token), ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80, 'os_version' => '24.04', 'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample'])
        ->assertSee('apt-get install -yq postgresql'."\n", escape: false)
        ->assertDontSee('pgvector');
});

test('postgres shares memory with php and redis within the server', function (int $cores, int $memoryMb) {
    $specs = new ServerSpecs($cores, $memoryMb, 80);
    $tuning = (new ServerTuner)->tune($specs, false, true, withPostgres: true);

    $allocated = (new ServerTuner)->operatingSystemReserveMb($specs)
        + $tuning->postgres['shared_buffers_mb']
        + $tuning->redis['maxmemory_mb']
        + $tuning->php['primary_pool']['max_children'] * ServerTuner::PHP_PROCESS_SIZE_MB;

    expect($tuning->mysql)->toBeNull()
        ->and($allocated)->toBeLessThanOrEqual($memoryMb)
        ->and($tuning->postgres['max_connections'])->toBeGreaterThanOrEqual($tuning->php['primary_pool']['max_children'])
        ->and(array_column($tuning->memoryAllocation($memoryMb), 'service'))->toContain('PostgreSQL')->not->toContain('MySQL');
})->with([
    '2 cores / 2GB' => [2, 2048],
    '4 cores / 8GB' => [4, 8192],
    '8 cores / 16GB' => [8, 16384],
]);

test('postgres databases are closed to other roles and get the vector extension', function () {
    $shell = fakeShell();
    $server = Server::factory()->pgvector()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', 'shop')
        ->call('createDatabase')
        ->assertHasNoErrors();

    expect($server->databases()->sole()->status)->toBe(ResourceStatus::Installed);

    expect($shell->lastScript())
        ->toContain("createdb --encoding=UTF8 --template=template0 'shop'")
        ->toContain('REVOKE ALL ON DATABASE "shop" FROM PUBLIC;')
        ->toContain("-d 'shop' -c 'CREATE EXTENSION IF NOT EXISTS vector;'")
        ->not->toContain('mysql');
});

test('plain postgres databases do not enable the vector extension', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', 'shop')
        ->call('createDatabase');

    expect($shell->lastScript())->toContain('createdb')->not->toContain('vector');
});

test('postgres users are granted their databases and lose access to every other one', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create();
    $shop = Database::factory()->for($server)->postgres()->create(['name' => 'shop']);
    Database::factory()->for($server)->postgres()->create(['name' => 'blog']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', 'shop_user')
        ->set('userDatabases', [$shop->id])
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($server->databaseUsers()->sole()->status)->toBe(ResourceStatus::Installed);

    expect($shell->lastScript())
        ->toContain("datname <> ALL (ARRAY['shop']::text[])")
        ->toContain("EXECUTE format('REVOKE ALL ON DATABASE %I FROM %I', other_database, 'shop_user');")
        ->toContain('ALTER ROLE "shop_user" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD')
        ->toContain('GRANT ALL PRIVILEGES ON DATABASE "shop" TO "shop_user";')
        ->toContain("\\connect \"shop\"\nGRANT ALL ON SCHEMA public TO \"shop_user\";")
        ->not->toContain('"blog"');
});

test('a postgres user with no databases has access to none', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', 'reporting')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($shell->lastScript())->toContain('datname <> ALL (ARRAY[]::text[])')->not->toContain('GRANT ALL');
});

test('postgres reserved role names cannot be used for database users or site databases', function (string $username) {
    $server = Server::factory()->postgres()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', $username)
        ->call('saveUser')
        ->assertHasErrors(['username']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', $username)
        ->call('createDatabase')
        ->assertHasErrors(['name']);
})->with(['postgres', 'pg_monitor', 'PG_signal_backend']);

test('deleting a postgres user keeps the tables it owned', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create();
    $user = DatabaseUser::factory()->for($server)->postgres()->create(['username' => 'shop_user']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('deleteUser', $user->id);

    expect($shell->lastScript())
        ->toContain('REASSIGN OWNED BY "shop_user" TO postgres; DROP OWNED BY "shop_user";')
        ->toContain('DROP ROLE IF EXISTS "shop_user";');
});

test('postgres databases are dropped even with open connections', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create();
    $database = Database::factory()->for($server)->postgres()->create(['name' => 'shop']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('deleteDatabase', $database->id);

    expect($shell->lastScript())->toContain('DROP DATABASE IF EXISTS "shop" WITH (FORCE);');
});

test('a site on a postgres server connects with pgsql', function () {
    $site = Site::factory()->for(Server::factory()->postgres())->create();

    expect(SiteEnvironment::defaults($site, 'shop'))
        ->toContain("DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=shop");
});

test('creating a site on a postgres server creates its database and a user named after the site\'s system user', function () {
    $shell = fakeShell();
    $server = Server::factory()->pgvector()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'shop.example.com')
        ->set('create_database', true)
        ->set('database', 'shop')
        ->call('create')
        ->assertHasNoErrors();

    $shell->assertRan('CREATE EXTENSION IF NOT EXISTS vector;');
    $shell->assertRan('GRANT ALL PRIVILEGES ON DATABASE "shop" TO "shop_example";');
});

test('postgres overrides go in their own file after swage\'s and restart postgres', function () {
    $shell = fakeShell();
    $server = tunedPostgresServer();

    Livewire::actingAs($server->user)
        ->test('pages::servers.config', ['server' => $server, 'service' => 'postgres'])
        ->set('override', 'max_connections = 300')
        ->call('save');

    expect($shell->lastScript())
        ->toContain("/etc/postgresql/16/main/conf.d/99-swage-custom.conf' <<'")
        ->toContain('max_connections = 300')
        ->not->toMatch('/^max_connections = \d+$(?<!300)/m')
        ->toContain('postgres -C shared_buffers --config-file=/etc/postgresql/16/main/postgresql.conf')
        ->toContain('systemctl restart postgresql')
        ->toContain('pg_isready --quiet');
});

test('a postgres server offers postgres config and logs instead of mysql', function () {
    $server = tunedPostgresServer();

    expect(ConfigService::availableOn($server))->toContain(ConfigService::Postgres)->not->toContain(ConfigService::Mysql)
        ->and(collect(LogFiles::forServer($server))->map->location()->all())
        ->toContain('/var/log/postgresql/postgresql-16-main.log')
        ->not->toContain('/var/log/mysql/error.log');
});

test('the server page lists the installed engines and offers the missing ones', function () {
    $server = Server::factory()->pgvector()->create(['status' => ServerStatus::Active]);

    $this->actingAs($server->user)->get(route('servers.show', $server))
        ->assertSee('PostgreSQL 16 + pgvector')
        ->assertSee('Install MySQL')
        ->assertDontSee('Install PostgreSQL')
        ->assertDontSee('Add pgvector');
});

test('mysql can be installed later and the server is re-tuned to share memory with it', function () {
    $shell = fakeShell()->respondTo('SWAGE_CPU_CORES', new ScriptResult(0, "SWAGE_CPU_CORES 4\nSWAGE_MEMORY_MB 8192\nSWAGE_DISK_GB 160"));
    $server = Server::factory()->postgres()->create(['status' => ServerStatus::Active]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installDatabaseEngine', 'mysql');

    $server->refresh();

    expect($server->has_mysql)->toBeTrue()
        ->and($server->has_postgres)->toBeTrue()
        ->and($server->installing_database)->toBeNull()
        ->and($server->tuning['mysql'])->not->toBeNull()
        ->and($server->tuning['postgres']['shared_buffers_mb'])->toBe((int) floor(8192 * 0.15));

    $shell->assertRan('apt-get install -yq mysql-server');
    $shell->assertRan("CREATE USER IF NOT EXISTS 'swage'@'%'");
    expect($shell->lastScript())->toContain('systemctl restart mysql')->toContain('systemctl restart postgresql');
});

test('postgres can be installed on a running server', function () {
    $shell = fakeShell()->respondTo('SWAGE_CPU_CORES', new ScriptResult(0, "SWAGE_CPU_CORES 2\nSWAGE_MEMORY_MB 4096\nSWAGE_DISK_GB 80"));
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installDatabaseEngine', 'postgres');

    expect($server->refresh()->databaseEngines())->toBe([DatabaseEngine::Mysql, DatabaseEngine::Postgres]);

    $shell->assertRan("ALTER ROLE \"swage\" WITH LOGIN SUPERUSER PASSWORD '{$server->database_password}';");
});

test('an engine the server already runs cannot be installed again', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installDatabaseEngine', 'mysql')
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

test('only one database install runs on a server at a time', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'installing_database' => 'postgres', 'has_mysql' => false]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installDatabaseEngine', 'mysql');

    Queue::assertNothingPushed();
    expect($server->refresh()->installing_database)->toBe('postgres');
});

test('a failed install can be tried again', function () {
    fakeShell()->respondTo('apt-get install -yq mysql-server', new ScriptResult(100, 'E: Unable to locate package'));
    $server = Server::factory()->postgres()->create(['status' => ServerStatus::Active]);

    rescue(fn () => Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])->call('installDatabaseEngine', 'mysql'), report: false);

    expect($server->refresh())
        ->has_mysql->toBeFalse()
        ->installing_database->toBeNull();
});

test('pgvector can be added later and is enabled in existing postgres databases only', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create(['status' => ServerStatus::Active, 'has_mysql' => true]);
    Database::factory()->for($server)->postgres()->create(['name' => 'shop']);
    Database::factory()->for($server)->create(['name' => 'legacy']);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPgvector');

    expect($server->refresh()->has_pgvector)->toBeTrue();
    expect($shell->lastScript())
        ->toContain('apt-get install -yq postgresql-16-pgvector')
        ->toContain("for database in 'shop'; do")
        ->not->toContain('legacy');
});

test('pgvector cannot be added to a server without postgres', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPgvector')
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

test('with both engines, databases and users are created on the chosen one', function () {
    $shell = fakeShell();
    $server = Server::factory()->pgvector()->create(['has_mysql' => true]);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->assertSet('engine', 'mysql')
        ->set('engine', 'postgres')
        ->set('name', 'shop')
        ->call('createDatabase')
        ->assertHasNoErrors();

    $database = $server->databases()->sole();
    expect($database->engine)->toBe(DatabaseEngine::Postgres);
    $shell->assertRan('CREATE EXTENSION IF NOT EXISTS vector;');

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', 'shop')
        ->call('createDatabase')
        ->assertHasNoErrors();

    $shell->assertRan('CREATE DATABASE IF NOT EXISTS `shop`');
    expect($server->databases()->pluck('engine')->map->value->sort()->values()->all())->toBe(['mysql', 'postgres']);
});

test('a database user can only be given databases on its own engine', function () {
    Queue::fake();
    $server = Server::factory()->postgres()->create(['has_mysql' => true]);
    $mysqlDatabase = Database::factory()->for($server)->create(['name' => 'legacy']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('userEngine', 'postgres')
        ->set('username', 'reporting')
        ->set('userDatabases', [$mysqlDatabase->id])
        ->call('saveUser')
        ->assertHasErrors(['userDatabases.0']);

    Queue::assertNothingPushed();
});

test('a site on a server with both engines can put its database on postgres', function () {
    $shell = fakeShell();
    $server = Server::factory()->postgres()->create(['has_mysql' => true]);

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'shop.example.com')
        ->set('create_database', true)
        ->set('database', 'shop')
        ->set('database_engine', 'postgres')
        ->call('create')
        ->assertHasNoErrors();

    expect($server->databases()->sole()->engine)->toBe(DatabaseEngine::Postgres)
        ->and($server->databaseUsers()->sole()->engine)->toBe(DatabaseEngine::Postgres);

    $shell->assertRan('createdb');
    $shell->assertRan('DB_CONNECTION=pgsql');
});

test('mysql and postgres split their memory so both fit with php and redis', function (int $cores, int $memoryMb) {
    $specs = new ServerSpecs($cores, $memoryMb, 80);
    $tuning = (new ServerTuner)->tune($specs, true, true, withPostgres: true);
    $postgresOnly = (new ServerTuner)->tune($specs, false, true, withPostgres: true);

    $allocated = (new ServerTuner)->operatingSystemReserveMb($specs)
        + $tuning->mysql['innodb_buffer_pool_size_mb']
        + $tuning->postgres['shared_buffers_mb']
        + $tuning->redis['maxmemory_mb']
        + $tuning->php['primary_pool']['max_children'] * ServerTuner::PHP_PROCESS_SIZE_MB;

    expect($allocated)->toBeLessThanOrEqual($memoryMb)
        ->and($tuning->postgres['shared_buffers_mb'])->toBeLessThan($postgresOnly->postgres['shared_buffers_mb'])
        ->and(array_column($tuning->memoryAllocation($memoryMb), 'service'))->toContain('MySQL', 'PostgreSQL');
})->with([
    '2 cores / 4GB' => [2, 4096],
    '4 cores / 8GB' => [4, 8192],
    '8 cores / 16GB' => [8, 16384],
]);
