<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Provisioning\ProvisioningProgress;
use App\Provisioning\ServerTuner;
use App\Ssh\Script;

/**
 * The step labels the provisioning script reports, in order.
 *
 * @return list<string>
 */
function reportedSteps(Server $server): array
{
    $script = Script::render('provision.server', ['server' => $server, 'tuning' => (new ServerTuner)->tune($server->specs(), true, true)]);

    preg_match_all("/^swage_step '(.+)'$/m", $script, $matches);

    return $matches[1];
}

test('the steps shown are the ones the provisioning script reports, in the same order', function (Server $server) {
    expect(reportedSteps($server))->toBe(ProvisioningProgress::scriptSteps($server))->not->toBeEmpty();
})->with([
    'app server with mysql and redis' => fn () => Server::factory()->create(['has_mysql' => true, 'has_redis' => true]),
    'app server with postgres and pgvector' => fn () => Server::factory()->postgres()->create(),
    'app server with nothing extra' => fn () => Server::factory()->create(['has_mysql' => false, 'has_redis' => false]),
    'database server' => fn () => Server::factory()->database()->create(),
]);

test('a server waiting for its command has every step still to come', function () {
    $states = collect(ProvisioningProgress::for(Server::factory()->pending()->create()))->pluck('state')->unique()->all();

    expect($states)->toBe(['pending']);
});

test('a provisioning server shows the steps done, the one under way and those to come', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_step' => 'Installing nginx']);

    $steps = collect(ProvisioningProgress::for($server))->pluck('state', 'label');

    expect($steps[ProvisioningProgress::HARDWARE_STEP])->toBe('done')
        ->and($steps['Configuring the firewall'])->toBe('done')
        ->and($steps['Installing nginx'])->toBe('current')
        ->and($steps['Optimizing services for this server'])->toBe('pending');
});

test('once the hardware is reported, the first step of the script is under way', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_step' => 'Starting']);

    $steps = collect(ProvisioningProgress::for($server))->pluck('state')->all();

    expect(array_slice($steps, 0, 3))->toBe(['done', 'current', 'pending']);
});

test('a failed server marks the step it failed at', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Failed, 'provisioning_step' => 'Installing nginx (failed at line 84)']);

    $steps = collect(ProvisioningProgress::for($server))->pluck('state', 'label');

    expect($steps['Configuring the firewall'])->toBe('done')
        ->and($steps['Installing nginx'])->toBe('failed')
        ->and($steps['Optimizing services for this server'])->toBe('pending');
});

test('the server page lists the steps while it is provisioned', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_step' => 'Installing nginx']);

    $this->actingAs($server->user)->get(route('servers.show', $server))
        ->assertOk()
        ->assertSeeInOrder(['Reporting the server&#039;s hardware', 'Configuring the firewall', 'Installing nginx', 'Optimizing services for this server'], false)
        ->assertSee(__(':done of :total done', ['done' => 4, 'total' => count(ProvisioningProgress::for($server))]));
});
