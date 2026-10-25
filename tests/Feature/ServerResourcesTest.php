<?php

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallScheduledJob;
use App\Jobs\SyncDatabaseUser;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Models\User;
use App\Ssh\Script;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('databases are created on the server', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', 'shop')
        ->call('createDatabase')
        ->assertHasNoErrors();

    expect($server->databases()->sole()->status)->toBe(ResourceStatus::Installed);
    $shell->assertRan('CREATE DATABASE IF NOT EXISTS `shop`');
});

test('database names cannot inject sql', function () {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', 'shop`; DROP DATABASE mysql; --')
        ->call('createDatabase')
        ->assertHasErrors(['name']);

    Queue::assertNothingPushed();
});

test('database users are granted access to the selected databases', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();
    $shop = Database::factory()->for($server)->create(['name' => 'shop']);
    Database::factory()->for($server)->create(['name' => 'blog']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', 'shop_user')
        ->set('userDatabases', [$shop->id])
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = $server->databaseUsers()->sole();

    expect($user->status)->toBe(ResourceStatus::Installed)
        ->and($user->databases->pluck('name')->all())->toBe(['shop']);

    $shell->assertRan("GRANT ALL PRIVILEGES ON `shop`.* TO 'shop_user'@'%'");
    expect($shell->lastScript())->not->toContain('`blog`');
});

test('database users cannot be granted databases from another server', function () {
    Queue::fake();
    $server = Server::factory()->create();
    $foreign = Database::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', 'shop_user')
        ->set('userDatabases', [$foreign->id])
        ->call('saveUser')
        ->assertHasErrors(['userDatabases.0']);

    Queue::assertNotPushed(SyncDatabaseUser::class);
});

test('database passwords cannot contain shell or sql quoting characters', function () {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('newUser')
        ->set('username', 'shop_user')
        ->set('password', "abc'def\"ghi\$jkl")
        ->call('saveUser')
        ->assertHasErrors(['password']);
});

test('daemons are installed as supervisor programs', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.daemons', ['server' => $server])
        ->set('command', 'node /home/swage/app/server.js')
        ->set('processes', 2)
        ->call('create')
        ->assertHasNoErrors();

    $daemon = $server->daemons()->sole();

    expect($daemon->status)->toBe(ResourceStatus::Installed);
    $shell->assertRan("[program:{$daemon->programName()}]");
    $shell->assertRan('numprocs=2');
});

test('daemon commands cannot span multiple lines', function () {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.daemons', ['server' => $server])
        ->set('command', "sleep 1\n[program:evil]")
        ->call('create')
        ->assertHasErrors(['command']);

    Queue::assertNotPushed(InstallDaemon::class);
});

test('scheduled jobs are written to cron', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.scheduler', ['server' => $server])
        ->set('command', 'php8.5 /home/swage/app/current/artisan backup:run')
        ->set('frequency', 'custom')
        ->set('custom_frequency', '*/5 * * * *')
        ->call('create')
        ->assertHasNoErrors();

    $job = $server->scheduledJobs()->sole();

    expect($job->frequency)->toBe('*/5 * * * *');
    $shell->assertRan("*/5 * * * * swage php8.5 /home/swage/app/current/artisan backup:run >> /home/swage/.swage/scheduled-job-{$job->id}.log 2>&1");
    $shell->assertRan("/home/swage/.swage/scheduled-job-{$job->id}.log {\n    su swage swage\n    size 10M");
    $shell->assertRan('sudo -u swage mkdir -p /home/swage/.swage');
});

test('scheduled jobs run as root log to root\'s home directory', function () {
    $job = ScheduledJob::factory()->create(['user' => 'root']);

    expect($job->logPath())->toBe("/root/.swage/scheduled-job-{$job->id}.log")
        ->and(Script::render('scheduled-jobs.install', ['scheduledJob' => $job]))->toContain("mkdir -p /root/.swage\n")
        ->and(Script::render('scheduled-jobs.remove', ['scheduledJob' => $job]))->toContain("'/root/.swage/scheduled-job-{$job->id}.log'*");
});

test('custom cron expressions are validated', function () {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.scheduler', ['server' => $server])
        ->set('command', 'echo hi')
        ->set('frequency', 'custom')
        ->set('custom_frequency', 'every tuesday')
        ->call('create')
        ->assertHasErrors(['custom_frequency']);

    Queue::assertNotPushed(InstallScheduledJob::class);
});

test('resources on another user\'s server cannot be managed', function () {
    $server = Server::factory()->create();
    $databaseUser = DatabaseUser::factory()->for($server)->create();

    Livewire::actingAs(User::factory()->create())->test('pages::servers.databases', ['server' => $server])
        ->assertForbidden();

    expect($databaseUser->fresh())->not->toBeNull();
});

test('system databases cannot be created', function (string $name) {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->set('name', $name)
        ->call('createDatabase')
        ->assertHasErrors(['name' => 'not_regex']);

    Queue::assertNothingPushed();
})->with(['mysql', 'sys', 'performance_schema', 'information_schema', 'MySQL']);

test('system databases are never dropped or granted, even when a record for one exists', function () {
    $server = Server::factory()->create();
    $mysql = Database::factory()->for($server)->create(['name' => 'mysql']);
    $shop = Database::factory()->for($server)->create(['name' => 'shop']);
    $user = DatabaseUser::factory()->for($server)->create(['username' => 'shop_user']);
    $user->databases()->attach([$mysql->id, $shop->id]);

    expect(Script::render('databases.delete', ['database' => $mysql]))->not->toContain('DROP DATABASE')
        ->and(Script::render('databases.create', ['database' => $mysql]))->not->toContain('CREATE DATABASE')
        ->and(Script::render('databases.sync-user', ['databaseUser' => $user->load('databases')]))
        ->toContain('ON `shop`.*')
        ->not->toContain('ON `mysql`.*');
});

test('an existing database user cannot be renamed', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();
    $user = DatabaseUser::factory()->for($server)->create(['username' => 'shop_user']);

    Livewire::actingAs($server->user)->test('pages::servers.databases', ['server' => $server])
        ->call('editUser', $user->id)
        ->set('username', 'renamed')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->fresh()->username)->toBe('shop_user');
    $shell->assertRan("ALTER USER 'shop_user'@'%'");
    expect($shell->lastScript())->not->toContain('renamed');
});

test('percent signs in daemon commands are escaped for supervisor', function () {
    $server = Server::factory()->create();
    $daemon = Daemon::factory()->for($server)->create(['command' => 'bash -c "echo $(date +%s)"']);

    expect(Script::render('daemons.install', ['daemon' => $daemon]))
        ->toContain('command=bash -c "echo $(date +%%s)"')
        ->toContain('process_name=%(program_name)s_%(process_num)02d');
});
