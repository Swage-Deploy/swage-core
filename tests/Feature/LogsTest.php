<?php

use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Jobs\InstallScheduledJob;
use App\Models\Daemon;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteCommands;
use App\Ssh\LogFile;
use App\Ssh\LogFiles;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('a site lists its laravel, scheduler, worker, nginx and php logs', function () {
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com', 'php_version' => PhpVersion::Php85, 'private_redis' => true]);
    $scheduler = $site->server->scheduledJobs()->create([
        'site_id' => $site->id, 'command' => SiteCommands::scheduler($site), 'user' => 'example', 'frequency' => '* * * * *', 'status' => ResourceStatus::Installed,
    ]);
    $worker = Daemon::factory()->for($site->server)->create(['site_id' => $site->id, 'user' => 'example']);

    $logs = collect(LogFiles::forSite($site))->mapWithKeys(fn (LogFile $log) => [$log->label => $log->location()]);

    expect($logs->all())->toBe([
        'Laravel' => '/home/example/example.com/shared/storage/logs/laravel*.log',
        'Scheduler' => "/home/example/.swage/scheduled-job-{$scheduler->id}.log",
        "Queue worker #{$worker->id}" => "/home/swage/.swage/daemon-{$worker->id}.log",
        'nginx access' => '/var/log/nginx/example.com-access.log',
        'nginx errors' => '/var/log/nginx/example.com-error.log',
        'PHP 8.5 FPM' => '/var/log/php8.5-fpm.log',
        'Private Redis' => 'journalctl -u swage-redis@example',
    ]);
});

test('a site deployed in place shows the laravel log from its checkout', function () {
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com', 'zero_downtime' => false]);

    expect(LogFiles::find(LogFiles::forSite($site), 'laravel')->location())
        ->toBe('/home/example/example.com/current/storage/logs/laravel*.log');
});

test('a server lists its service, process and system logs', function () {
    $server = Server::factory()->create(['php_versions' => [PhpVersion::Php84, PhpVersion::Php85], 'has_mysql' => true, 'has_redis' => true]);
    $daemon = Daemon::factory()->for($server)->create();

    $locations = collect(LogFiles::forServer($server))->map->location();

    expect($locations->all())->toContain(
        '/var/log/nginx/error.log',
        '/var/log/php8.4-fpm.log',
        '/var/log/php8.5-fpm.log',
        '/var/log/mysql/error.log',
        '/var/log/redis/redis-server.log',
        '/var/log/supervisor/supervisord.log',
        "/home/swage/.swage/daemon-{$daemon->id}.log",
        '/var/log/syslog',
        '/var/log/auth.log',
    );
});

test('the newest of a split log is read, filtered and capped', function () {
    $command = LogFile::newest('laravel', 'Laravel', 'Application', '/home/example/example.com/shared/storage/logs', 'laravel*.log')
        ->command(500, "it's broken");

    expect($command)
        ->toContain("ls -1t -- '/home/example/example.com/shared/storage/logs'/laravel*.log")
        ->toContain("grep -iF -- 'it'\\''s broken'")
        ->toContain('tail -n 500 | tail -c '.LogFile::MAX_BYTES);
});

test('a site\'s laravel log is shown with secrets masked', function () {
    $site = Site::factory()->create(['domain' => 'example.com']);
    $shell = fakeShell()->respondTo('laravel*.log', new ScriptResult(0, "SWAGE_LOG_FOUND /home/swage/example.com/shared/storage/logs/laravel-2026-10-16.log\n[2026-10-16] production.ERROR: connecting with {$site->server->database_password}\n"));

    Livewire::actingAs($site->server->user)->test('pages::sites.logs', ['server' => $site->server, 'site' => $site])
        ->call('readLog')
        ->assertSet('source', '/home/swage/example.com/shared/storage/logs/laravel-2026-10-16.log')
        ->assertSet('output', "[2026-10-16] production.ERROR: connecting with ********\n")
        ->assertSee('production.ERROR')
        ->set('log', 'nginx-error');

    $shell->assertRan("f='/var/log/nginx/example.com-error.log'");
});

test('the scheduler box links to its log, which offers a reinstall when nothing was logged yet', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $scheduler = $site->server->scheduledJobs()->create([
        'site_id' => $site->id, 'command' => SiteCommands::scheduler($site), 'user' => 'swage', 'frequency' => '* * * * *', 'status' => ResourceStatus::Installed,
    ]);
    fakeShell()->respondTo('scheduled-job', new ScriptResult(0, ''));

    Livewire::actingAs($site->server->user)->test('pages::sites.queues', ['server' => $site->server, 'site' => $site])
        ->assertSee(route('servers.sites.logs', [$site->server, $site, 'log' => "scheduled-job-{$scheduler->id}"]), escape: false);

    Livewire::withQueryParams(['log' => "scheduled-job-{$scheduler->id}"])
        ->actingAs($site->server->user)
        ->test('pages::sites.logs', ['server' => $site->server, 'site' => $site])
        ->call('readLog')
        ->assertSet('output', false)
        ->assertSee('Nothing has been logged yet')
        ->call('reinstallScheduledJob');

    Bus::assertDispatched(InstallScheduledJob::class, fn (InstallScheduledJob $job) => $job->scheduledJob->is($scheduler));
});

test('server logs can be read', function () {
    $server = Server::factory()->create();
    $shell = fakeShell()->respondTo('syslog', new ScriptResult(0, "SWAGE_LOG_FOUND /var/log/syslog\nOct 16 kernel: hello\n"));

    Livewire::actingAs($server->user)->test('pages::servers.logs', ['server' => $server])
        ->assertSee('Logins and sudo')
        ->set('log', 'syslog')
        ->assertSet('output', "Oct 16 kernel: hello\n");

    $shell->assertRan("f='/var/log/syslog'");
});

test('filters cannot span lines and line counts are limited', function () {
    $server = Server::factory()->create();
    fakeShell();

    Livewire::actingAs($server->user)->test('pages::servers.logs', ['server' => $server])
        ->set('filter', "a\nb")
        ->assertHasErrors(['filter'])
        ->set('filter', '')
        ->set('lines', 99999)
        ->assertHasErrors(['lines']);
});

test('logs on another user\'s server cannot be read', function () {
    $site = Site::factory()->create();

    Livewire::actingAs(Server::factory()->create()->user)->test('pages::sites.logs', ['server' => $site->server, 'site' => $site])->assertForbidden();
    Livewire::actingAs(Server::factory()->create()->user)->test('pages::servers.logs', ['server' => $site->server])->assertForbidden();
});

test('following a log stops by itself after a while', function () {
    $site = Site::factory()->create();
    fakeShell()->respondTo('laravel*.log', new ScriptResult(0, "SWAGE_LOG_FOUND /home/swage/example.com/shared/storage/logs/laravel.log\nline\n"));

    $page = Livewire::actingAs($site->server->user)->test('pages::sites.logs', ['server' => $site->server, 'site' => $site])
        ->set('follow', true)
        ->call('readLog')
        ->assertSet('follow', true);

    $this->travel(11)->minutes();

    $page->call('readLog')->assertSet('follow', false)->assertSet('followingSince', null);
});
