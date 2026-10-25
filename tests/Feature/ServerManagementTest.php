<?php

use App\Enums\ServerStatus;
use App\Jobs\OptimizeServer;
use App\Models\Certificate;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerTuner;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('guests cannot see servers', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('users only see their own servers', function () {
    $user = User::factory()->create();
    $mine = Server::factory()->for($user)->create();
    $theirs = Server::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee($mine->name)
        ->assertDontSee($theirs->name);
});

test('users cannot open servers they do not own', function (string $route) {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route($route, $server))
        ->assertForbidden();
})->with(['servers.show', 'servers.sites', 'servers.databases', 'servers.daemons', 'servers.scheduler', 'servers.activity']);

test('a server can be connected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::servers.create')
        ->set('name', 'production-1')
        ->set('ip_address', '203.0.113.10')
        ->set('php_versions', ['8.4', '8.5'])
        ->set('default_php_version', '8.4')
        ->set('database', 'mysql')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $server = $user->servers()->sole();

    expect($server)
        ->status->toBe(ServerStatus::Pending)
        ->php_versions->map->value->all()->toBe(['8.4', '8.5'])
        ->ssh_public_key->toStartWith('ssh-ed25519 ')
        ->ssh_private_key->toContain('OPENSSH PRIVATE KEY')
        ->provision_token->toHaveLength(64);

    $this->actingAs($user)->get(route('servers.show', $server))->assertSee($server->provisionCommand());
});

test('the default php version must be installed', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('name', 'production-1')
        ->set('ip_address', '203.0.113.10')
        ->set('php_versions', ['8.4'])
        ->set('default_php_version', '8.5')
        ->call('save')
        ->assertHasErrors(['default_php_version']);
});

test('the server ip address must be valid', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('name', 'production-1')
        ->set('ip_address', 'not-an-ip; rm -rf /')
        ->call('save')
        ->assertHasErrors(['ip_address']);
});

test('re-optimizing detects the hardware and rewrites service configs', function () {
    $shell = fakeShell()->respondTo('nproc', new ScriptResult(0, "SWAGE_CPU_CORES 4\nSWAGE_MEMORY_MB 8192\nSWAGE_DISK_GB 160\n"));
    $server = Server::factory()->create();

    OptimizeServer::dispatchSync($server);

    $server->refresh();

    expect($server->cpu_cores)->toBe(4)
        ->and($server->memory_mb)->toBe(8192)
        ->and($server->tasks()->pluck('name')->all())->toBe(['Detecting server specs', 'Optimizing services']);

    $shell->assertRan('pm.max_children = '.$server->tuning['php']['primary_pool']['max_children']);
    $shell->assertRan('worker_processes 4;');
});

test('re-optimizing is queued from the overview page', function () {
    Queue::fake();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])->call('optimize');

    Queue::assertPushed(OptimizeServer::class, fn (OptimizeServer $job) => $job->server->is($server));
});

test('a server can only be removed after confirming its name', function () {
    $shell = fakeShell();
    $server = Server::factory()->create(['name' => 'production-1']);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->set('confirmation', 'wrong')
        ->call('delete')
        ->assertHasErrors(['confirmation']);

    expect($server->fresh())->not->toBeNull();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->set('confirmation', 'production-1')
        ->call('delete')
        ->assertRedirect(route('dashboard'));

    expect($server->fresh())->toBeNull();
    $shell->assertRan("grep -vF 'AAAAfake'");
});

test('every server and site page renders for its owner', function () {
    $site = Site::factory()->withRepository()->create();
    $server = $site->server;
    Deployment::factory()->for($site)->create();
    Certificate::factory()->for($site)->create();
    Database::factory()->for($server)->create();
    Daemon::factory()->for($server)->create(['site_id' => $site->id]);
    ScheduledJob::factory()->for($server)->create();
    Task::factory()->for($server)->create();
    $server->update(['tuning' => (new ServerTuner)->tune($server->specs(), true, true)->toArray()]);

    $this->actingAs($server->user);

    foreach (['servers.show', 'servers.sites', 'servers.databases', 'servers.daemons', 'servers.scheduler', 'servers.activity'] as $route) {
        $this->get(route($route, $server))->assertOk();
    }

    foreach (['servers.sites.show', 'servers.sites.ssl', 'servers.sites.queues', 'servers.sites.settings'] as $route) {
        $this->get(route($route, [$server, $site]))->assertOk()->assertSee($site->domain);
    }

    $this->get(route('servers.create'))->assertOk();
    $this->get(route('source-control.edit'))->assertOk();
});

test('the provision command stays visible after a livewire update', function () {
    $server = Server::factory()->pending()->create();

    $page = $this->actingAs($server->user)->get(route('servers.show', $server))->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $snapshot);

    $html = $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1]),
                'updates' => [],
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
            ]],
        ])
        ->assertOk()
        ->json('components.0.effects.html');

    expect(html_entity_decode($html))->toContain($server->provisionCommand())
        ->not->toContain('This server is not provisioned yet');
});

test('a server can be removed while it is still provisioning', function () {
    fakeShell();
    $server = Server::factory()->provisioning()->create();

    $page = $this->actingAs($server->user)->get(route('servers.show', $server))->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $snapshot);

    $html = $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1]),
                'updates' => [],
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
            ]],
        ])
        ->assertOk()
        ->json('components.0.effects.html');

    expect($html)->toContain('Remove server');

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->set('confirmation', $server->name)
        ->call('delete')
        ->assertRedirect(route('dashboard'));

    expect(Server::find($server->id))->toBeNull();
});

test('removing a server removes its sites\' deploy keys and webhooks from their provider', function () {
    $provider = fakeSourceControl();
    fakeShell();
    $site = Site::factory()->withRepository()->create(['webhook_id' => '99']);
    $server = $site->server;
    SourceControlConnection::factory()->for($server->user)->create();

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->set('confirmation', $server->name)
        ->call('delete');

    expect($server->fresh())->toBeNull()
        ->and($provider->client->calls)->toBe([
            ['deleteDeployKey', [$site->repository, $site->deploy_key_id]],
            ['deleteWebhook', [$site->repository, '99']],
        ]);
});

test('deleting an account releases each of its servers first', function () {
    $provider = fakeSourceControl();
    $shell = fakeShell();
    $site = Site::factory()->withRepository()->create(['webhook_id' => '99']);
    $server = $site->server;
    $user = $server->user;
    SourceControlConnection::factory()->for($user)->create();
    $server->update(['ssh_public_key' => 'ssh-ed25519 AAAAfake swage']);

    Livewire::actingAs($user)->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    expect($user->fresh())->toBeNull()
        ->and($server->fresh())->toBeNull();
    $shell->assertRan("grep -vF 'AAAAfake'");
    expect($provider->client->calls)->toBe([
        ['deleteDeployKey', [$site->repository, $site->deploy_key_id]],
        ['deleteWebhook', [$site->repository, '99']],
    ]);
});
