<?php

use App\Models\Server;
use App\Models\User;
use App\Servers\ServerIntegration;
use App\Servers\ServerIntegrations;
use Tests\Fakes\FakeServerIntegration;

function fakeServerIntegration(array $secrets = []): FakeServerIntegration
{
    return tap(new FakeServerIntegration($secrets), fn (FakeServerIntegration $integration) => app(ServerIntegrations::class)->register($integration));
}

test('a server\'s plugins tab shows the panel of every integration', function () {
    fakeServerIntegration();
    $server = Server::factory()->create(['name' => 'web-1']);

    $this->actingAs($server->user)
        ->get(route('servers.plugins', $server))
        ->assertOk()
        ->assertSee('Fake integration for web-1');
});

test('a server\'s plugins tab says when no server plugin is installed, linking administrators to them', function () {
    $server = Server::factory()->for(User::factory()->create(['is_admin' => true]))->create();

    $this->actingAs($server->user)
        ->get(route('servers.plugins', $server))
        ->assertOk()
        ->assertSee('No server plugins are installed')
        ->assertSee(route('plugins', 'server'));
});

test('other people cannot open a server\'s plugins tab', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('servers.plugins', $server))->assertForbidden();
});

test('the secrets server integrations store are redacted from task output', function () {
    fakeServerIntegration(['server-secret-token']);
    $server = Server::factory()->create();

    expect($server->redactSecrets('token=server-secret-token'))->toBe('token=********');
});

test('server integrations must have a valid id', function () {
    $integration = Mockery::mock(ServerIntegration::class);
    $integration->allows('id')->andReturn('Not Valid');

    app(ServerIntegrations::class)->register($integration);
})->throws(InvalidArgumentException::class, '[Not Valid] is not a valid server integration id.');
