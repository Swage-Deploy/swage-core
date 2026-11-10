<?php

use App\Enums\ServerStatus;
use App\Jobs\RecordServerMetrics;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function metricsOutput(): string
{
    return implode("\n", [
        'SWAGE_METRICS',
        'cpu  1000 0 500 8000 500 0 0 0 0 0',
        'cpu  1060 0 530 8090 520 0 0 0 0 0',
        'MemTotal:        4000000 kB',
        'MemAvailable:    1000000 kB',
        'SwapTotal:       2000000 kB',
        'SwapFree:        1500000 kB',
        'loadavg 0.52 0.40 0.31',
        'uptime 1209600.55',
        'disk 80000000000 60000000000',
    ]);
}

function serverMetric(Server $server, array $attributes = []): ServerMetric
{
    return $server->metrics()->forceCreate([...ServerMetric::parse(metricsOutput()), ...$attributes]);
}

test('the metrics script reads cpu over a second, memory, swap, load, uptime and the root disk', function () {
    $script = Script::render('server-metrics');

    expect($script)
        ->toContain('/proc/stat')
        ->toContain('sleep 1')
        ->toContain('MemAvailable')
        ->toContain('/proc/loadavg')
        ->toContain('/proc/uptime')
        ->toContain('df -P -B 1 /');
});

test('readings are parsed from the script output', function () {
    // 200 ticks passed, 110 of them idle or waiting on disk.
    expect(ServerMetric::parse(metricsOutput()))->toBe([
        'cpu_percent' => 45.0,
        'load_1' => 0.52,
        'load_5' => 0.40,
        'load_15' => 0.31,
        'memory_total' => 4000000 * 1024,
        'memory_used' => 3000000 * 1024,
        'swap_total' => 2000000 * 1024,
        'swap_used' => 500000 * 1024,
        'disk_total' => 80000000000,
        'disk_used' => 60000000000,
        'uptime_seconds' => 1209600,
    ]);
});

test('output that is not from the metrics script is ignored', function (string $output) {
    expect(ServerMetric::parse($output))->toBeNull();
})->with([
    'empty' => '',
    'other output' => 'Welcome to Ubuntu',
    'missing cpu' => "SWAGE_METRICS\nMemTotal: 1 kB\nMemAvailable: 1 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\nloadavg 0 0 0\nuptime 1\ndisk 1 1",
]);

test('a reading is recorded for the server', function () {
    $shell = fakeShell()->respondTo('SWAGE_METRICS', new ScriptResult(0, metricsOutput()));
    $server = Server::factory()->create();

    RecordServerMetrics::dispatchSync($server);

    expect($server->metrics()->sole())
        ->cpu_percent->toBe(45.0)
        ->memoryPercent()->toBe(75.0)
        ->diskPercent()->toBe(75.0);

    $shell->assertRan('/proc/meminfo');
});

test('a server that cannot be reached is left without a reading', function () {
    fakeShell()->respondTo('SWAGE_METRICS', new ScriptResult(255, 'Connection refused'));
    $server = Server::factory()->create();

    RecordServerMetrics::dispatchSync($server);

    expect($server->metrics()->count())->toBe(0);
});

test('every active server is read every five minutes', function () {
    Queue::fake();
    $active = Server::factory()->create();
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $this->artisan('swage:record-metrics')->assertSuccessful();

    Queue::assertPushed(RecordServerMetrics::class, 1);
    Queue::assertPushed(RecordServerMetrics::class, fn (RecordServerMetrics $job): bool => $job->server->is($active));
});

test('readings older than a week are pruned', function () {
    $server = Server::factory()->create();
    $old = serverMetric($server, ['created_at' => now()->subDays(8)]);
    $recent = serverMetric($server, ['created_at' => now()->subDays(6)]);

    $this->artisan('model:prune', ['--model' => ServerMetric::class])->assertSuccessful();

    expect(ServerMetric::find($old->id))->toBeNull()
        ->and(ServerMetric::find($recent->id))->not->toBeNull();
});

test('the overview shows the latest reading with its trends', function () {
    $server = Server::factory()->create(['cpu_cores' => 2]);
    serverMetric($server, ['created_at' => now()->subMinutes(10), 'cpu_percent' => 12.5]);
    serverMetric($server, ['created_at' => now()->subMinutes(5), 'disk_used' => 76000000000]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('System monitor')
        ->assertSee('45.0%')
        ->assertSee('0.52')
        ->assertSee('75%')
        ->assertSee('95%')
        ->assertSee('Filling up')
        ->assertSee('2 cores')
        ->assertSee('Up 2 weeks')
        ->assertDontSee('No readings yet');
});

test('the overview says when there are no readings yet', function () {
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('No readings yet');
});

test('the overview warns when the server has not been read for a while', function () {
    $server = Server::factory()->create();
    serverMetric($server, ['created_at' => now()->subHour()]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('Swage hasn\'t been able to read the server since.');
});

test('servers still being provisioned have no system monitor', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertDontSee('System monitor');
});

test('refreshing takes a reading now, at most every 15 seconds', function () {
    $shell = fakeShell()->respondTo('SWAGE_METRICS', new ScriptResult(0, metricsOutput()));
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('refreshMetrics')
        ->assertSee('45.0%')
        ->call('refreshMetrics');

    expect($server->metrics()->count())->toBe(1);
});

test('a refresh that cannot reach the server says so', function () {
    fakeShell()->respondTo('SWAGE_METRICS', new ScriptResult(255, 'Connection refused'));
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('refreshMetrics')
        ->assertDispatched('toast-show');
});
