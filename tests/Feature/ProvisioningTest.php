<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use Livewire\Livewire;

test('a pending server serves its bootstrap script', function () {
    $server = Server::factory()->pending()->create();

    $this->get(route('provision.script', $server->provision_token))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=UTF-8')
        ->assertSee(route('provision.specs', $server->provision_token), escape: false);
});

test('the bootstrap never overwrites itself with the provisioning script', function () {
    $server = Server::factory()->pending()->create();

    $bootstrap = $this->get(route('provision.script', $server->provision_token))->getContent();
    preg_match('/-o (\\S+) &&/', $server->provisionCommand(), $download);
    preg_match('/-o (\\S+)\\s*$/m', $bootstrap, $provisionScript);

    expect($download[1])->toBe('/tmp/swage-bootstrap.sh')
        ->and($provisionScript[1])->toBe('/root/swage-provision.sh')
        ->and($bootstrap)->toContain("main \"\$@\"\nexit\n");
});

test('the provision command installs curl when the server lacks it, before downloading the bootstrap', function () {
    $command = Server::factory()->pending()->create()->provisionCommand();

    expect($command)->toStartWith('(command -v curl > /dev/null || (apt-get update -qq && apt-get install -yqq curl > /dev/null)) && curl -fsSL ');
});

test('the bootstrap script is unavailable once a server is active', function () {
    $server = Server::factory()->create();

    $this->get(route('provision.script', $server->provision_token))->assertNotFound();
});

test('reporting specs returns a provisioning script tuned to the hardware', function () {
    $server = Server::factory()->pending()->create();

    $response = $this->post(route('provision.specs', $server->provision_token), [
        'cpu_cores' => 2,
        'memory_mb' => 4096,
        'disk_gb' => 80,
        'os_version' => '24.04',
    ]);

    $server->refresh();

    $response->assertOk()
        ->assertSee('pm.max_children = '.$server->tuning['php']['primary_pool']['max_children'], escape: false)
        ->assertSee('innodb_buffer_pool_size = '.$server->tuning['mysql']['innodb_buffer_pool_size_mb'].'M', escape: false)
        ->assertSee('maxmemory '.$server->tuning['redis']['maxmemory_mb'].'mb', escape: false)
        ->assertSee('worker_processes 2;', escape: false)
        ->assertSee($server->ssh_public_key, escape: false);

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->cpu_cores)->toBe(2)
        ->and($server->memory_mb)->toBe(4096)
        ->and($server->disk_gb)->toBe(80);
});

test('the provisioning script leaves swap and kernel settings to a container host', function () {
    $server = Server::factory()->pending()->create();

    $script = $this->post(route('provision.specs', $server->provision_token), [
        'cpu_cores' => 2,
        'memory_mb' => 4096,
        'disk_gb' => 80,
        'os_version' => '24.04',
    ])->assertOk()->getContent();

    $containerCheck = strpos($script, 'if swage_in_container; then');

    expect($script)->toContain('systemd-detect-virt --quiet --container')
        ->and($containerCheck)->toBeLessThan(strpos($script, 'swapon /swapfile'))
        ->and($script)->toContain('sysctl -q -w "${SWAGE_SETTING// = /=}"')
        ->and($script)->toContain('Skipped kernel settings that only the machine running this container can change')
        ->and(strpos($script, 'if ! swage_in_container; then'))->toBeLessThan(strpos($script, 'systemctl enable --now disable-thp.service'));
});

test('reported specs are validated', function () {
    $server = Server::factory()->pending()->create();

    $this->postJson(route('provision.specs', $server->provision_token), ['cpu_cores' => 0, 'memory_mb' => 'lots'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cpu_cores', 'memory_mb', 'disk_gb', 'os_version']);

    expect($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('the provisioning script reports progress, completion and failure', function () {
    $server = Server::factory()->provisioning()->create();

    $this->post(route('provision.progress', $server->provision_token), ['step' => 'Installing MySQL'])->assertNoContent();
    expect($server->fresh()->provisioning_step)->toBe('Installing MySQL');

    $this->post(route('provision.failed', $server->provision_token), ['line' => 42])->assertNoContent();
    expect($server->fresh())
        ->status->toBe(ServerStatus::Failed)
        ->provisioning_step->toBe('Installing MySQL (failed at line 42)');
});

test('a finished server becomes active and stops accepting callbacks', function () {
    $server = Server::factory()->provisioning()->create();

    $this->post(route('provision.finished', $server->provision_token))->assertNoContent();

    expect($server->fresh())
        ->status->toBe(ServerStatus::Active)
        ->provisioned_at->not->toBeNull();

    $this->post(route('provision.progress', $server->provision_token), ['step' => 'Hijacked'])->assertNotFound();
});

test('provisioning that stopped reporting progress can be started over with a new command', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_step' => 'Installing MySQL', 'provision_token' => 'old-token']);
    $this->travel(Server::PROVISIONING_STALL_MINUTES + 1)->minutes();

    $this->actingAs($server->user)->get(route('servers.show', $server))->assertSee(__('Start over'));

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])->call('restartProvisioning');

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Failed)
        ->and($server->provisioning_step)->toBe('Installing MySQL (stopped responding)')
        ->and($server->provision_token)->not->toBe('old-token');

    $this->get(route('provision.script', $server->provision_token))->assertOk();
    $this->post(route('provision.finished', 'old-token'))->assertNotFound();
});

test('provisioning that is still reporting progress cannot be started over', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provision_token' => 'token']);

    $this->actingAs($server->user)->get(route('servers.show', $server))->assertOk()->assertDontSee(__('Start over'));

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('restartProvisioning')
        ->assertStatus(409);

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning);
});
