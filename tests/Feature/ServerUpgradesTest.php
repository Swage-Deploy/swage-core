<?php

use App\Enums\ConfigService;
use App\Enums\DatabaseEngine;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Jobs\UpgradeServer;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Provisioning\ServerUpgrades;
use App\Provisioning\ServiceConfigFiles;
use App\Provisioning\Upgrades\ExactDatabaseGrants;
use App\Provisioning\Upgrades\PrivateRedisForIsolatedSites;
use App\Provisioning\Upgrades\RedisAuthentication;
use App\Provisioning\Upgrades\RestrictSudo;
use App\Provisioning\Upgrades\RootOwnedDaemonLogs;
use App\Provisioning\Upgrades\SeparateNginxUser;
use App\Provisioning\Upgrades\SiteDatabaseUsers;
use App\Provisioning\Upgrades\SiteSymlinkOwnership;
use App\Sites\SiteEnvironmentFile;
use App\Sites\SiteNginxConfig;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeRemoteShell;

function legacyServer(array $attributes = []): Server
{
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85], ...$attributes]);

    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres())->toArray()]);

    return $server;
}

/**
 * Make the fake shell answer a read of the site's .env with the given contents.
 */
function respondWithEnvironment(FakeRemoteShell $shell, Site $site, string $environment): void
{
    $shell->respondTo("echo SWAGE_ENV_FOUND; cat '{$site->path()}/shared/.env'", new ScriptResult(0, "SWAGE_ENV_FOUND\n{$environment}\n"));
}

/**
 * @return list<string>
 */
function scriptsRun(FakeRemoteShell $shell): array
{
    return array_column($shell->ran, 'script');
}

test('servers provisioned before an upgrade have it pending and new servers do not', function () {
    expect(array_keys(legacyServer()->pendingUpgrades()))->toBe(ServerUpgrades::ids())
        ->and(Server::factory()->upgraded()->create()->pendingUpgrades())->toBe([]);
});

test('upgrading a server applies each pending upgrade in order and records it', function () {
    fakeShell();
    $server = legacyServer(['applied_upgrades' => [RestrictSudo::ID]]);

    UpgradeServer::dispatchSync($server);

    expect($server->fresh()->applied_upgrades)->toBe(ServerUpgrades::ids())
        ->and($server->tasks()->pluck('name')->first())->toBe('Moving nginx to its own user');
});

test('a failed upgrade keeps the ones before it and stops there', function () {
    fakeShell()->respondTo('usermod -aG swage www-data', new ScriptResult(1, 'usermod: failed'));
    $server = legacyServer();

    expect(fn () => UpgradeServer::dispatchSync($server))->toThrow(Exception::class);

    expect($server->fresh()->applied_upgrades)->toBe([RestrictSudo::ID]);
});

test('the overview lists pending upgrades and applies them', function () {
    Queue::fake();
    $server = legacyServer();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('Security upgrades available')
        ->assertSee('Run nginx as its own user')
        ->call('applyUpgrades');

    Queue::assertPushed(UpgradeServer::class, fn (UpgradeServer $job) => $job->server->is($server));
});

test('only the owner can apply upgrades', function () {
    $server = legacyServer();
    $other = Server::factory()->create();

    Livewire::actingAs($other->user)->test('pages::servers.show', ['server' => $server])->assertForbidden();
});

test('the console command lists and queues servers with pending upgrades', function () {
    Queue::fake();
    $legacy = legacyServer();
    Server::factory()->upgraded()->create();

    $this->artisan('swage:upgrade-servers', ['--list' => true])->expectsOutputToContain($legacy->name)->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('swage:upgrade-servers')->assertSuccessful();
    Queue::assertPushed(UpgradeServer::class, 1);
});

test('restricting sudo replaces the wildcard rules with helpers for swage and every isolated site', function () {
    $shell = fakeShell();
    $server = legacyServer();
    Site::factory()->for($server)->isolated('example')->create();

    app(RestrictSudo::class)->apply($server);

    expect($shell->lastScript())
        ->toContain('cat > /usr/local/sbin/swage-reload-php')
        ->toContain('grep -qxF "user=$SUDO_USER" "$CONFIG"')
        ->toContain('swage ALL=(root) NOPASSWD: /usr/local/sbin/swage-reload-php, /usr/local/sbin/swage-restart-daemon')
        ->toContain('example ALL=(root) NOPASSWD: /usr/local/sbin/swage-reload-php, /usr/local/sbin/swage-restart-daemon')
        ->toContain('visudo -cf /etc/sudoers.d/swage-site-example.new')
        ->not->toContain('php*-fpm')
        ->not->toContain('restart *');
});

test('moving nginx to its own user regroups users, rewrites the configs and restarts php-fpm', function () {
    $shell = fakeShell();
    $server = legacyServer();
    Site::factory()->for($server)->isolated('example')->create();
    $sharedWorker = Daemon::factory()->for($server)->create(['user' => 'swage']);

    app(SeparateNginxUser::class)->apply($server);

    $script = $shell->lastScript();

    expect($script)
        ->toContain('usermod -aG swage www-data')
        ->toContain('usermod -aG example www-data')
        ->toContain('gpasswd -d swage example')
        ->toContain("user www-data;\n")
        ->toContain("listen = /run/php/php8.5-fpm-example.sock\nlisten.owner = www-data\nlisten.group = www-data")
        ->toContain('chown -R www-data:www-data /var/lib/nginx')
        ->toContain('systemctl restart php8.4-fpm')
        ->toContain("supervisorctl restart '{$sharedWorker->programName()}:*'")
        ->and(strpos($script, 'systemctl reload nginx'))->toBeLessThan(strpos($script, 'systemctl restart php8.5-fpm'))
        ->and($server->fresh()->webUser())->toBe('swage');
});

test('configs keep using swage\'s user for nginx until the server is upgraded', function () {
    $legacy = legacyServer();
    $upgraded = legacyServer(['applied_upgrades' => ServerUpgrades::ids()]);

    expect(ServiceConfigFiles::for($legacy)->base(ConfigService::Nginx))->toStartWith('user swage;')
        ->and(ServiceConfigFiles::for($upgraded)->base(ConfigService::Nginx))->toStartWith('user www-data;');
});

test('sites using the shared mysql account move to their own user before the shared password changes', function () {
    $shell = fakeShell();
    $server = legacyServer();
    $oldPassword = $server->database_password;
    $shared = Site::factory()->for($server)->create(['domain' => 'shop.example.com']);
    $isolated = Site::factory()->for($server)->isolated('blog')->create();
    $ownUser = Site::factory()->for($server)->create();

    respondWithEnvironment($shell, $shared, "APP_NAME=Shop\nDB_HOST=127.0.0.1\nDB_DATABASE=shop\nDB_USERNAME=swage\nDB_PASSWORD={$oldPassword}");
    respondWithEnvironment($shell, $isolated, "DB_DATABASE=blog\nDB_USERNAME=swage\nDB_PASSWORD={$oldPassword}");
    respondWithEnvironment($shell, $ownUser, "DB_DATABASE=other\nDB_USERNAME=other\nDB_PASSWORD=secret");

    app(SiteDatabaseUsers::class)->apply($server);

    $shopUser = $server->databaseUsers()->where('username', 'shop_example')->sole();
    $blogUser = $server->databaseUsers()->where('username', 'blog')->sole();

    expect($shopUser->databases->pluck('name')->all())->toBe(['shop'])
        ->and($shopUser->status)->toBe(ResourceStatus::Installed)
        ->and($blogUser->databases->pluck('name')->all())->toBe(['blog'])
        ->and($server->databases()->where('name', 'other')->exists())->toBeFalse()
        ->and($server->fresh()->database_password)->not->toBe($oldPassword);

    $scripts = implode("\n", scriptsRun($shell));

    expect($scripts)
        ->toContain("GRANT ALL PRIVILEGES ON `shop`.* TO 'shop_example'@'%'")
        ->toContain('swage_env_set DB_USERNAME '.escapeshellarg(base64_encode('DB_USERNAME=shop_example')))
        ->toContain('swage_env_set DB_PASSWORD '.escapeshellarg(base64_encode("DB_PASSWORD={$shopUser->password}")))
        ->toContain("ALTER USER IF EXISTS 'swage'@'%' IDENTIFIED BY '{$server->fresh()->database_password}'")
        ->and(strrpos($scripts, 'swage_env_set DB_USERNAME'))->toBeLessThan(strpos($scripts, 'ALTER USER IF EXISTS'));
});

test('the mysql upgrade stops when a site\'s database name cannot be used', function () {
    $shell = fakeShell();
    $server = legacyServer();
    $site = Site::factory()->for($server)->create();
    respondWithEnvironment($shell, $site, "DB_DATABASE=\"my db\"\nDB_USERNAME=swage");
    $password = $server->database_password;

    expect(fn () => app(SiteDatabaseUsers::class)->apply($server))->toThrow(RuntimeException::class, 'is not a database name');

    expect($server->fresh()->database_password)->toBe($password);
});

test('redis authentication adds the password user, updates each shared site, then turns off anonymous access', function () {
    $shell = fakeShell();
    $server = legacyServer();
    $shared = Site::factory()->for($server)->create();
    $private = Site::factory()->for($server)->isolated('example')->create(['private_redis' => true]);
    $remote = Site::factory()->for($server)->create();

    respondWithEnvironment($shell, $shared, "REDIS_HOST=127.0.0.1\nREDIS_PASSWORD=null");
    respondWithEnvironment($shell, $remote, 'REDIS_HOST=cache.internal');

    app(RedisAuthentication::class)->apply($server);

    $server->refresh();
    $scripts = scriptsRun($shell);
    $all = implode("\n", $scripts);

    expect($server->redis_password)->toHaveLength(40)
        ->and($scripts[0])->toContain("redis-cli ACL SETUSER swage reset on '#".hash('sha256', $server->redis_password)."'")
        ->and($scripts[0])->not->toContain($server->redis_password)
        ->and($all)->toContain('swage_env_set REDIS_PASSWORD '.escapeshellarg(base64_encode("REDIS_PASSWORD={$server->redis_password}")))
        ->and(substr_count($all, 'swage_env_set REDIS_USERNAME'))->toBe(1)
        ->and(end($scripts))->toContain("user default off\nuser swage on #".hash('sha256', $server->redis_password))
        ->and(end($scripts))->toContain('redis-cli ACL SETUSER default off')
        ->and($server->usesRedisAuthentication())->toBeFalse();

    $server->markUpgradeApplied(RedisAuthentication::ID);

    expect($server->usesRedisAuthentication())->toBeTrue();
});

test('existing isolated sites can move to a private redis server', function () {
    $shell = fakeShell();
    $server = legacyServer();
    $site = Site::factory()->for($server)->isolated('example')->create();

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->assertSee('Use private Redis')
        ->call('enablePrivateRedis');

    expect($site->fresh()->private_redis)->toBeTrue()
        ->and($shell->lastScript())
        ->toContain('cat > /etc/systemd/system/swage-redis@.service')
        ->toContain('unixsocket /run/swage-redis-example/redis.sock')
        ->toContain('ExecStart=/usr/bin/redis-server /etc/swage-redis/%i.conf')
        ->toContain('chown root:example /etc/swage-redis/example.conf')
        ->not->toContain('/etc/redis/sites')
        ->toContain('systemctl restart swage-redis@example')
        ->toContain('swage_env_set REDIS_HOST '.escapeshellarg(base64_encode('REDIS_HOST=/run/swage-redis-example/redis.sock')))
        ->toContain('swage_env_unset REDIS_PASSWORD');
});

test('a site that fails to move to private redis stays on the shared server', function () {
    fakeShell()->respondTo('swage-redis@', new ScriptResult(1, 'redis failed'));
    $server = legacyServer();
    $site = Site::factory()->for($server)->isolated('example')->create();

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->call('enablePrivateRedis');

    expect($site->fresh()->private_redis)->toBeFalse();
});

test('isolated sites on the shared redis move to private redis, then the shared password changes without refusing a site', function () {
    $shell = fakeShell();
    $server = legacyServer(['has_redis' => true, 'redis_password' => 'OldSharedPassword0123456789abcdefghijklm']);
    $server->markUpgradeApplied(RedisAuthentication::ID);
    $previous = $server->redis_password;
    $isolated = Site::factory()->for($server)->isolated('example')->create(['private_redis' => false]);
    $alreadyPrivate = Site::factory()->for($server)->isolated('other')->create(['private_redis' => true]);
    $shared = Site::factory()->for($server)->create();
    $remote = Site::factory()->for($server)->create();

    respondWithEnvironment($shell, $shared, "REDIS_HOST=127.0.0.1\nREDIS_USERNAME=swage\nREDIS_PASSWORD={$previous}");
    respondWithEnvironment($shell, $remote, 'REDIS_HOST=cache.internal');

    app(PrivateRedisForIsolatedSites::class)->apply($server);

    $server->refresh();
    $password = $server->redis_password;
    $scripts = scriptsRun($shell);
    $added = collect($scripts)->search(fn (string $script): bool => str_contains($script, 'Added a new password'));
    $switched = collect($scripts)->search(fn (string $script): bool => str_contains($script, 'swage_env_set REDIS_PASSWORD '.escapeshellarg(base64_encode("REDIS_PASSWORD={$password}"))));
    $removed = collect($scripts)->search(fn (string $script): bool => str_contains($script, 'Removed the old password'));

    expect($isolated->fresh()->private_redis)->toBeTrue()
        ->and(implode("\n", $scripts))->toContain('systemctl restart swage-redis@example')
        ->not->toContain('systemctl restart swage-redis@other')
        ->and($password)->not->toBe($previous)->toHaveLength(40)
        ->and($added)->toBeInt()->toBeLessThan($switched)
        ->and($switched)->toBeLessThan($removed)
        ->and($scripts[$added])->toContain("REDISCLI_AUTH='{$previous}'")
        ->toContain("ACL SETUSER swage '#".hash('sha256', $password)."'")
        ->not->toContain('reset')
        ->not->toContain($password)
        ->and($scripts[$removed])->toContain("REDISCLI_AUTH='{$password}'")
        ->toContain("ACL SETUSER swage reset on '#".hash('sha256', $password)."'")
        ->toContain('user swage on #'.hash('sha256', $password))
        ->not->toContain(hash('sha256', $previous))
        ->and(substr_count(implode("\n", $scripts), 'swage_env_set REDIS_PASSWORD'))->toBe(1);
});

test('servers without redis have nothing to move to private redis', function () {
    $shell = fakeShell();
    $server = legacyServer(['has_redis' => false, 'redis_password' => null]);
    Site::factory()->for($server)->isolated('example')->create(['private_redis' => false]);

    app(PrivateRedisForIsolatedSites::class)->apply($server);

    $shell->assertNothingRan();
});

test('the environment is read as the site\'s user when swage needs its values', function () {
    $shell = fakeShell();
    $site = Site::factory()->isolated('example')->create();
    respondWithEnvironment($shell, $site, 'APP_NAME=Example');

    expect(app(SiteEnvironmentFile::class)->read($site))->toBe(['APP_NAME' => 'Example'])
        ->and($shell->ran[0]['as'])->toBe('example');
});

test('sites only follow links to files their owner owns, which the upgrade writes into every installed site', function () {
    $shell = fakeShell();
    $server = legacyServer();
    $installed = Site::factory()->for($server)->create(['domain' => 'shop.com', 'status' => ResourceStatus::Installed, 'nginx_override' => 'client_max_body_size 1g;']);
    Site::factory()->for($server)->create(['domain' => 'pending.com', 'status' => ResourceStatus::Pending]);

    expect(SiteNginxConfig::for($installed)->base())->toContain('disable_symlinks if_not_owner from=$document_root;');

    app(SiteSymlinkOwnership::class)->apply($server);

    expect($shell->lastScript())
        ->toContain("cat > '/etc/nginx/sites-available/shop.com'")
        ->toContain('disable_symlinks if_not_owner from=$document_root;')
        ->toContain("client_max_body_size 1g;\n")
        ->not->toContain('pending.com')
        ->toContain("nginx -t\nsystemctl reload nginx");
});

test('mysql users are granted exactly their databases, with _ in the names escaped', function () {
    $shell = fakeShell();
    $server = legacyServer(['has_mysql' => true]);
    $shopDb = Database::factory()->for($server)->create(['name' => 'shop_db']);
    $user = DatabaseUser::factory()->for($server)->create(['username' => 'shop', 'engine' => DatabaseEngine::Mysql]);
    $user->databases()->attach($shopDb);
    DatabaseUser::factory()->for($server)->create(['username' => 'reporting', 'engine' => DatabaseEngine::Postgres]);

    app(ExactDatabaseGrants::class)->apply($server);

    expect(scriptsRun($shell))->toHaveCount(1)
        ->and($shell->lastScript())
        ->toContain("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'shop'@'%';")
        ->toContain("GRANT ALL PRIVILEGES ON `shop\\_db`.* TO 'shop'@'%';")
        ->not->toContain('`shop_db`');
});

test('daemons log to a root-owned directory once the server is upgraded', function () {
    $legacy = legacyServer();
    $upgraded = Server::factory()->upgraded()->create();

    expect(Daemon::factory()->for($legacy)->create()->logPath())->toStartWith('/home/swage/.swage/daemon-')
        ->and(Daemon::factory()->for($upgraded)->create()->logPath())->toStartWith('/var/log/swage/daemon-');
});

test('moving daemon logs rewrites every daemon to log to the root-owned directory', function () {
    $shell = fakeShell();
    $server = legacyServer(['applied_upgrades' => array_values(array_diff(ServerUpgrades::ids(), [RootOwnedDaemonLogs::ID]))]);
    $daemon = Daemon::factory()->for($server)->create();

    UpgradeServer::dispatchSync($server);

    expect($server->fresh()->hasAppliedUpgrade(RootOwnedDaemonLogs::ID))->toBeTrue()
        ->and($shell->lastScript())
        ->toContain('install -d -m 750 -o root -g adm /var/log/swage')
        ->toContain("stdout_logfile=/var/log/swage/{$daemon->programName()}.log")
        ->toContain('supervisorctl update');
});
