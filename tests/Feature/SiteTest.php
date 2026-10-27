<?php

use App\Actions\Sites\CreateSite;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Exceptions\ScriptFailedException;
use App\Jobs\CreateDatabase;
use App\Jobs\DeploySite;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallScheduledJob;
use App\Jobs\InstallSite;
use App\Jobs\RemoveSite;
use App\Jobs\SyncDatabaseUser;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Certificate;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use App\Provisioning\ServerTuner;
use App\Sites\SiteSystemUser;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('new sites run as their own user with their own database and database user by default', function () {
    Bus::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'Example.com')
        ->assertSet('system_user', 'example')
        ->assertSet('database', 'example')
        ->set('aliases', 'www.example.com, example.com')
        ->call('create')
        ->assertHasNoErrors();

    $site = $server->sites()->sole();
    $databaseUser = $server->databaseUsers()->sole();

    expect($site)
        ->domain->toBe('example.com')
        ->system_user->toBe('example')
        ->aliases->toBe(['www.example.com'])
        ->status->toBe(ResourceStatus::Pending)
        ->and($databaseUser->username)->toBe('example')
        ->and(strlen($databaseUser->password))->toBe(40)
        ->and($databaseUser->databases->pluck('name')->all())->toBe(['example']);

    Bus::assertChained([CreateDatabase::class, SyncDatabaseUser::class, InstallSite::class, InstallScheduledJob::class]);

    expect($site->scheduledJobs()->sole())
        ->command->toBe('php8.5 /home/example/example.com/current/artisan schedule:run')
        ->user->toBe('example')
        ->frequency->toBe('* * * * *');
});

test('the scheduler can be left off for a new site', function () {
    Bus::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('scheduler', false)
        ->call('create')
        ->assertHasNoErrors();

    expect($server->scheduledJobs()->exists())->toBeFalse();

    Bus::assertChained([CreateDatabase::class, SyncDatabaseUser::class, InstallSite::class]);
});

test('the database and scheduler can be turned off, but not isolation', function () {
    Bus::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->assertDontSee('Isolate site')
        ->set('domain', 'example.com')
        ->set('create_database', false)
        ->set('scheduler', false)
        ->call('create')
        ->assertHasNoErrors();

    expect($server->sites()->sole()->system_user)->toBe('example')
        ->and($server->databases()->exists())->toBeFalse()
        ->and($server->databaseUsers()->exists())->toBeFalse();

    Bus::assertChained([InstallSite::class]);
});

test('suggested names never overwrite names the user typed', function () {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('system_user', 'custom')
        ->set('domain', 'example.org')
        ->assertSet('system_user', 'custom')
        ->assertSet('database', 'example');
});

test('suggested names skip those already taken on the server', function () {
    $server = Server::factory()->create();
    Site::factory()->for($server)->systemUser('example')->create();

    expect(SiteSystemUser::suggest('www.example.com', $server))->toBe('example_2')
        ->and(SiteSystemUser::suggest('app.example.co.uk', $server))->toBe('app_example_co')
        ->and(SiteSystemUser::suggest('123.com', $server))->toBe('site_123');
});

test('system users are validated', function (string $systemUser, Closure $setUp) {
    $server = Server::factory()->create();
    $setUp($server);

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('system_user', $systemUser)
        ->call('create')
        ->assertHasErrors(['system_user']);

    expect($server->sites()->where('domain', 'example.com')->exists())->toBeFalse();
})->with([
    'reserved' => ['root', fn () => null],
    'swage' => ['swage', fn () => null],
    'invalid characters' => ['Bad-User', fn () => null],
    'starts with a digit' => ['1site', fn () => null],
    'too long' => [str_repeat('a', 33), fn () => null],
    'trailing line break' => ["custom\n", fn () => null],
    'used by another site' => ['taken', fn (Server $server) => Site::factory()->for($server)->systemUser('taken')->create()],
    'used by a database user' => ['taken', fn (Server $server) => $server->databaseUsers()->create(['username' => 'taken', 'password' => 'secret', 'status' => ResourceStatus::Installed])],
]);

test('site domains are validated', function (string $domain) {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', $domain)
        ->call('create')
        ->assertHasErrors(['domain']);
})->with(['localhost', "example.com'; rm -rf /", '-bad.com', 'exa mple.com']);

test('a site cannot be created on another user\'s server', function () {
    $server = Server::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::servers.sites', ['server' => $server])
        ->assertForbidden();
});

test('sites cannot be reached through another server', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create();
    $otherSite = Site::factory()->create();

    $this->actingAs($user)->get(route('servers.sites.show', [$server, $otherSite]))->assertNotFound();
});

test('installing a site writes its nginx config and a production environment', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['domain' => 'example.com', 'php_version' => PhpVersion::Php85, 'status' => ResourceStatus::Pending]);

    InstallSite::dispatchSync($site, 'example');

    expect($site->fresh()->status)->toBe(ResourceStatus::Installed);

    $shell->assertRan('server_name example.com;');
    $shell->assertRan('fastcgi_pass unix:/run/php/php8.5-fpm-example.sock;');
    $shell->assertRan('root /home/example/example.com/current/public;');
    $shell->assertRan('DB_DATABASE=example');
    $shell->assertRan('APP_ENV=production');
    $shell->assertRan('REDIS_HOST=/run/swage-redis-example/redis.sock');
});

test('installing a site creates its user and pool and connects as its database user', function () {
    $shell = fakeShell();
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $site = Site::factory()->for($server)->systemUser('example')->create(['domain' => 'example.com', 'php_version' => PhpVersion::Php85, 'status' => ResourceStatus::Pending]);
    $databaseUser = $server->databaseUsers()->create(['username' => 'example', 'password' => 'generated-password', 'status' => ResourceStatus::Installed]);

    InstallSite::dispatchSync($site, 'example', $databaseUser);

    $shell->assertRan("useradd --create-home --user-group --comment 'Swage site' --shell /bin/bash example");
    $shell->assertRan('chmod 750 /home/example');
    $shell->assertRan('usermod -aG example www-data');
    $shell->assertRan('visudo -cf /etc/sudoers.d/swage-site-example.new');
    $shell->assertRan("cat > '/etc/php/8.5/fpm/pool.d/example.conf'");
    $shell->assertRan("[example]\nuser = example\ngroup = example\nlisten = /run/php/php8.5-fpm-example.sock");
    $shell->assertRan("rm -f '/etc/php/8.4/fpm/pool.d/example.conf'");
    $shell->assertRan('sudo -u example mkdir -p "$SITE_PATH/releases"');
    $shell->assertRan("SITE_PATH='/home/example/example.com'");
    $shell->assertRan('fastcgi_pass unix:/run/php/php8.5-fpm-example.sock;');
    $shell->assertRan('root /home/example/example.com/current/public;');
    $shell->assertRan("DB_DATABASE=example\nDB_USERNAME=example\nDB_PASSWORD=generated-password");
});

test('a site deploys and runs its workers as its own user', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->withRepository()->create();

    DeploySite::dispatchSync(Deployment::factory()->for($site)->create());

    expect(collect($shell->ran)->pluck('as')->all())->toBe(['example']);
    $shell->assertRan('ssh -i \'/home/example/.ssh/swage_site_'.$site->id.'\'');

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->call('createWorker')
        ->call('toggleScheduler');

    expect($site->daemons()->sole()->user)->toBe('example')
        ->and($site->scheduledJobs()->sole()->user)->toBe('example');
});

test('changing a site\'s php version moves its pool', function () {
    $shell = fakeShell();
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $site = Site::factory()->for($server)->systemUser('example')->create(['php_version' => PhpVersion::Php85]);

    $site->update(['php_version' => PhpVersion::Php84]);
    UpdateSiteConfiguration::dispatchSync($site);

    $shell->assertRan("cat > '/etc/php/8.4/fpm/pool.d/example.conf'");
    $shell->assertRan("rm -f '/etc/php/8.5/fpm/pool.d/example.conf'");
    $shell->assertRan('fastcgi_pass unix:/run/php/php8.4-fpm-example.sock;');
});

test('removing a site removes its pool and user', function () {
    Http::fake();
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create();

    RemoveSite::dispatchSync($site);

    $shell->assertRan("rm -f '/etc/php/8.5/fpm/pool.d/example.conf' /etc/sudoers.d/swage-site-example");
    $shell->assertRan('userdel --remove example');
    $shell->assertRan("certbot delete --non-interactive --cert-name '{$site->domain}'");
    expect(Site::find($site->id))->toBeNull();
});

test('optimizing a server re-tunes its sites\' pools', function () {
    $server = Server::factory()->create();
    $tuning = (new ServerTuner)->tune($server->specs(), withMysql: true, withRedis: true);
    Site::factory()->for($server)->systemUser('example')->create();

    $script = Script::render('optimize-server', ['server' => $server, 'tuning' => $tuning]);

    expect($script)->toContain("cat > '/etc/php/8.5/fpm/pool.d/example.conf'")
        ->toContain('pm.max_children = '.$tuning->php['secondary_pool']['max_children']);
});

test('the system user of a site cannot be changed', function () {
    $site = Site::factory()->systemUser('example')->create();

    expect(fn () => $site->update(['system_user' => 'other']))->toThrow(LogicException::class);
});

test('a failed install marks the site as failed and records the output', function () {
    fakeShell()->respondTo('sites-available', new ScriptResult(1, 'nginx: configuration file test failed'));
    $site = Site::factory()->create(['status' => ResourceStatus::Pending]);

    expect(fn () => InstallSite::dispatchSync($site))->toThrow(ScriptFailedException::class);

    $task = $site->server->tasks()->sole();

    expect($site->fresh()->status)->toBe(ResourceStatus::Failed)
        ->and($task->exit_code)->toBe(1)
        ->and($task->output)->toContain('configuration file test failed');
});

test('changing the php version moves the site, its workers and scheduler to the new binary', function () {
    Bus::fake();
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85]]);
    $site = Site::factory()->for($server)->create(['php_version' => PhpVersion::Php84]);
    $worker = Daemon::factory()->for($server)->create(['site_id' => $site->id, 'command' => "php8.4 {$site->currentPath()}/artisan queue:work"]);

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->set('php_version', '8.5')
        ->call('save')
        ->assertHasNoErrors();

    expect($site->fresh()->php_version)->toBe(PhpVersion::Php85)
        ->and($worker->fresh()->command)->toBe("php8.5 {$site->currentPath()}/artisan queue:work");

    Bus::assertDispatched(UpdateSiteConfiguration::class);
    Bus::assertDispatched(InstallDaemon::class);
});

test('removing a site cleans up the server and the site\'s provider', function () {
    $provider = fakeSourceControl();
    $shell = fakeShell();
    $site = Site::factory()->withRepository()->create(['webhook_id' => '99']);
    SourceControlConnection::factory()->for($site->server->user)->create();
    Daemon::factory()->for($site->server)->create(['site_id' => $site->id]);

    RemoveSite::dispatchSync($site);

    expect($site->fresh())->toBeNull();
    $shell->assertRan("userdel --remove {$site->system_user}");
    $shell->assertRan('supervisorctl update');

    expect($provider->client->calls)->toBe([
        ['deleteDeployKey', [$site->repository, $site->deploy_key_id]],
        ['deleteWebhook', [$site->repository, '99']],
    ]);
});

test('installing a site refuses to take over an account swage did not create', function () {
    $site = Site::factory()->systemUser('admin')->create();

    $script = Script::render('sites.install', ['site' => $site, 'certificate' => null, 'environment' => '']);

    expect($script)
        ->toContain("if [ \"\$(getent passwd admin | cut -d: -f5)\" != 'Swage site' ]; then")
        ->toContain('exit 1')
        ->and(strpos($script, 'exit 1'))->toBeLessThan(strpos($script, 'usermod -aG admin'));
});

test('system database names cannot be used for a new site', function (string $name) {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('system_user', 'example')
        ->set('database', $name)
        ->call('create')
        ->assertHasErrors(['database' => 'not_regex']);

    expect($server->sites()->count())->toBe(0);
})->with(['mysql', 'sys', 'performance_schema', 'Information_Schema']);

test('removing a site removes its deploy key and webhook from its provider after the site is gone', function () {
    $provider = fakeSourceControl();
    fakeShell();
    $site = Site::factory()->withRepository()->create(['webhook_id' => '99']);
    SourceControlConnection::factory()->for($site->server->user)->create();

    RemoveSite::dispatchSync($site);

    expect($site->fresh())->toBeNull()
        ->and($provider->client->calls)->toContain(['deleteWebhook', [$site->repository, '99']]);
});

test('a new site cannot take over a database that already exists', function () {
    $server = Server::factory()->create();
    Database::factory()->for($server)->create(['name' => 'shop']);

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('system_user', 'example')
        ->set('database', 'shop')
        ->call('create')
        ->assertHasErrors(['database' => 'unique']);

    expect($server->sites()->count())->toBe(0);
});

test('a site that cannot be saved in full leaves nothing behind', function () {
    Bus::fake();
    $server = Server::factory()->create();
    DatabaseUser::factory()->for($server)->create(['username' => 'example']);

    expect(fn () => app(CreateSite::class)->handle($server, [
        'domain' => 'example.com', 'system_user' => 'example', 'aliases' => [], 'web_directory' => '/public',
        'php_version' => '8.5', 'zero_downtime' => true, 'database' => 'example', 'scheduler' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($server->sites()->count())->toBe(0)
        ->and($server->databases()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('the sites list explains its ssl and zero downtime icons on hover', function () {
    $server = Server::factory()->create();
    $secured = Site::factory()->for($server)->create(['zero_downtime' => true]);
    Certificate::factory()->for($secured)->create();
    Site::factory()->for($server)->create();

    $this->actingAs($server->user)
        ->get(route('servers.sites', $server))
        ->assertOk()
        ->assertSee('SSL certificate active')
        ->assertSee('No SSL certificate')
        ->assertSee('Zero downtime deployments');
});

test('a site cannot take a name another site on the same server answers to', function () {
    Bus::fake();
    $server = Server::factory()->create();
    Site::factory()->for($server)->create(['domain' => 'shop.com', 'aliases' => ['www.shop.com']]);
    $site = Site::factory()->for($server)->create(['domain' => 'example.com', 'aliases' => []]);

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->set('aliases', 'www.example.com, WWW.SHOP.COM')
        ->call('save')
        ->assertHasErrors(['aliases' => 'www.shop.com is already used by shop.com on this server.']);

    expect($site->fresh()->aliases)->toBe([]);

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'www.shop.com')
        ->call('create')
        ->assertHasErrors(['domain' => 'www.shop.com is already used by shop.com on this server.'])
        ->set('domain', 'blog.com')
        ->set('aliases', 'shop.com')
        ->call('create')
        ->assertHasErrors(['aliases' => 'shop.com is already used by shop.com on this server.']);

    expect($server->sites()->count())->toBe(2);
});

test('a site keeps its own aliases and may share names with sites on other servers', function () {
    Bus::fake();
    $server = Server::factory()->create();
    Site::factory()->create(['domain' => 'shop.com']);
    $site = Site::factory()->for($server)->create(['domain' => 'example.com', 'aliases' => ['www.example.com']]);

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->set('aliases', 'www.example.com, shop.com')
        ->call('save')
        ->assertHasNoErrors();

    expect($site->fresh()->aliases)->toBe(['www.example.com', 'shop.com']);
});
