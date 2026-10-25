<?php

use App\Models\Server;
use App\Models\User;
use App\Plugins\HasPluginTab;
use App\Servers\ServerIntegration;
use App\Servers\ServerIntegrations;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Fakes\FakeServerIntegration;

function fakeServerIntegration(array $secrets = []): FakeServerIntegration
{
    return tap(new FakeServerIntegration($secrets), fn (FakeServerIntegration $integration) => app(ServerIntegrations::class)->register($integration));
}

test('a server\'s plugins tab shows the panel of its only integration, without tabs', function () {
    fakeServerIntegration();
    $server = Server::factory()->create(['name' => 'web-1']);

    $this->actingAs($server->user)
        ->get(route('servers.plugins', $server))
        ->assertOk()
        ->assertSee('Fake integration for web-1')
        ->assertDontSee('Fake Server Integration');
});

test('a server\'s plugins tab gives each integration a tab, showing one at a time', function () {
    fakeServerIntegration();
    app(ServerIntegrations::class)->register(new class extends FakeServerIntegration implements HasPluginTab
    {
        public function __construct()
        {
            Livewire::component('second-server-integration.settings', new class extends Component
            {
                public Server $server;

                public function render(): string
                {
                    return '<div>Second integration for {{ $server->name }}</div>';
                }
            });
        }

        public function id(): string
        {
            return 'second-integration';
        }

        public function settingsComponent(): string
        {
            return 'second-server-integration.settings';
        }

        public function tabLabel(): string
        {
            return 'Second plugin';
        }

        public function tabIcon(): string
        {
            return 'shield-check';
        }
    });
    $server = Server::factory()->create(['name' => 'web-1']);

    $this->actingAs($server->user)
        ->get(route('servers.plugins', $server))
        ->assertOk()
        ->assertSeeInOrder(['Fake Server Integration', 'Second plugin'])
        ->assertSee('Fake integration for web-1')
        ->assertDontSee('Second integration for web-1');

    $this->actingAs($server->user)
        ->get(route('servers.plugins', ['server' => $server, 'plugin' => 'second-integration']))
        ->assertOk()
        ->assertSee('Second integration for web-1')
        ->assertDontSee('Fake integration for web-1');

    Livewire::actingAs($server->user)->test('pages::servers.plugins', ['server' => $server])
        ->set('plugin', 'second-integration')
        ->assertSee('Second integration for web-1')
        ->set('plugin', 'not-installed')
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
