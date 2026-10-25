<?php

use App\Deployments\DeployScript;
use App\Enums\ConfigService;
use App\Enums\DatabaseEngine;
use App\Enums\PhpVersion;
use App\Models\Certificate;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Provisioning\ServiceConfigFiles;
use App\Sites\SiteEnvironment;
use App\Ssh\Script;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to check script syntax. Install it where PHP runs, such as with apk add bash in an Alpine container.');
    }
});

function assertValidBash(string $script): void
{
    $result = Process::input($script)->run('bash -n');

    expect($result->successful())->toBeTrue($result->errorOutput());
}

test('provisioning scripts are valid bash', function (bool $mysql, bool $postgres, bool $redis) {
    $server = Server::factory()->create([
        'php_versions' => [PhpVersion::Php84, PhpVersion::Php85],
        'has_mysql' => $mysql,
        'has_postgres' => $postgres,
        'has_pgvector' => $postgres,
        'has_redis' => $redis,
    ]);
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), $redis, $server->usesPostgres());

    assertValidBash(Script::render('provision.bootstrap', ['server' => $server]));
    assertValidBash(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]));
})->with([
    'full stack' => [true, false, true],
    'postgres with pgvector' => [false, true, true],
    'php only' => [false, false, false],
]);

test('database server provisioning and access scripts are valid bash', function () {
    $server = Server::factory()->database()->postgres()->create(['has_mysql' => true, 'private_ip_address' => '10.0.0.5', 'database_access' => ['10.0.0.9', '10.1.0.0/16', '2001:db8::1']]);
    $tuning = (new ServerTuner)->tune($server->specs(), true, false, withPostgres: true, withPhp: false);

    assertValidBash(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]));
    assertValidBash(Script::render('databases.sync-access', ['server' => $server]));
});

test('postgres database and install scripts are valid bash', function () {
    $server = Server::factory()->pgvector()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $database = Database::factory()->for($server)->postgres()->create(['name' => 'shop']);
    $databaseUser = DatabaseUser::factory()->for($server)->postgres()->create(['username' => 'shop']);
    $databaseUser->databases()->attach($database);

    assertValidBash(Script::render('databases.create', ['database' => $database]));
    assertValidBash(Script::render('databases.delete', ['database' => $database]));
    assertValidBash(Script::render('databases.sync-user', ['databaseUser' => $databaseUser]));
    assertValidBash(Script::render('databases.delete-user', ['databaseUser' => $databaseUser]));
    assertValidBash(Script::render('databases.install-pgvector', ['databases' => collect([$database])]));
    assertValidBash(Script::render('databases.install-pgvector', ['databases' => collect()]));

    foreach (DatabaseEngine::cases() as $engine) {
        assertValidBash(Script::render('databases.install-engine', ['server' => $server, 'engine' => $engine]));
    }
});

test('site scripts are valid bash', function (bool $isolated) {
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $factory = Site::factory()->for($server)->withRepository();
    $site = ($isolated ? $factory->isolated('example') : $factory)->create(['aliases' => ['www.example.com']]);
    Daemon::factory()->for($site->server)->create(['site_id' => $site->id]);
    $certificate = Certificate::factory()->for($site)->create();

    assertValidBash(Script::render('sites.install', ['site' => $site, 'certificate' => null, 'environment' => SiteEnvironment::defaults($site)]));
    assertValidBash(Script::render('sites.update-nginx', ['site' => $site, 'certificate' => null]));
    assertValidBash(Script::render('sites.remove', ['site' => $site]));
    assertValidBash(Script::render('certificates.obtain', ['site' => $site, 'certificate' => $certificate, 'email' => 'ops@example.com']));
    assertValidBash(DeployScript::render($site));
    assertValidBash(Script::render('sites.install-deploy-key', ['site' => $site]));
    assertValidBash(Script::render('sites.write-environment', ['site' => $site, 'environment' => 'APP_NAME=Example', 'delimiter' => 'SWAGE_ENV_X', 'clearConfig' => true]));
})->with([
    'shared' => [false],
    'isolated' => [true],
]);

test('server upgrade and private redis scripts are valid bash', function () {
    $server = Server::factory()->upgraded()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), true, true)->toArray()]);
    $site = Site::factory()->for($server)->isolated('example')->create(['private_redis' => true]);
    $configFiles = ServiceConfigFiles::for($server);

    assertValidBash(Script::render('upgrades.restrict-sudo', ['isolatedSites' => collect([$site])]));
    assertValidBash(Script::render('upgrades.separate-nginx-user', [
        'server' => $server,
        'nginxFiles' => $configFiles->files(ConfigService::Nginx),
        'poolFiles' => $configFiles->files(ConfigService::PhpFpmPool),
        'isolatedSites' => collect([$site]),
        'sharedDaemons' => collect(),
    ]));
    assertValidBash(Script::render('upgrades.rotate-mysql-password', ['password' => 'secret']));
    assertValidBash(Script::render('upgrades.redis-user', ['password' => 'secret']));
    assertValidBash(Script::render('upgrades.redis-lockdown', ['files' => $configFiles->files(ConfigService::Redis)]));
    assertValidBash(Script::render('revoke-access', ['publicKey' => 'AAAAkey']));
    assertValidBash(Script::render('upgrades.automatic-security-updates', ['server' => $server]));
    assertValidBash(Script::render('reboot-required'));
    assertValidBash(Script::render('reboot'));
    assertValidBash(Script::render('upgrades.root-owned-daemon-logs', ['daemons' => collect([Daemon::factory()->for($server)->create()])]));
    assertValidBash(Script::render('databases.delete', ['database' => Database::factory()->for($server)->make(['name' => 'mysql'])]));
    assertValidBash(Script::render('sites.update-environment', [
        'site' => $site->load('daemons'),
        'values' => ['DB_PASSWORD' => 'a"b$c d', 'REDIS_PASSWORD' => null],
        'setup' => Script::render('sites.private-redis', ['site' => $site]),
    ]));
});

test('environment values are read and written by the site user', function () {
    $site = Site::factory()->isolated('example')->create();
    $directory = sys_get_temp_dir().'/swage-env-'.Str::random(8);
    mkdir($directory);
    file_put_contents("{$directory}/.env", "APP_NAME=Example\nDB_PASSWORD=old\nREDIS_PASSWORD=secret\n");

    // Stand in for sudo: record the user each command runs as, then run it.
    $script = 'sudo() { echo "$2" >> "$SUDO_LOG"; shift 2; "$@"; }'."\n"
        .'ENV_FILE='.escapeshellarg("{$directory}/.env")."\n"
        .Script::render('sites.partials.set-env-values', [
            'site' => $site,
            'values' => ['DB_PASSWORD' => 'a"b$c d', 'REDIS_PASSWORD' => null, 'APP_DEBUG' => 'false'],
        ]);

    $result = Process::input($script)->env(['SUDO_LOG' => "{$directory}/sudo.log"])->run('bash -euo pipefail');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(file_get_contents("{$directory}/.env"))->toBe("APP_NAME=Example\nDB_PASSWORD=\"a\\\"b\\\$c d\"\nAPP_DEBUG=false\n")
        ->and(array_unique(file("{$directory}/sudo.log", FILE_IGNORE_NEW_LINES)))->toBe(['example']);

    File::deleteDirectory($directory);
});
