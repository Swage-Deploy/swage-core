<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Servers\ServerIntegration;
use Livewire\Component;
use Livewire\Livewire;

/**
 * An integration standing in for a plugin's. Its settings panel shows the server's name.
 */
class FakeServerIntegration implements ServerIntegration
{
    /**
     * @param  list<string>  $storedSecrets
     */
    public function __construct(public array $storedSecrets = [])
    {
        Livewire::component('fake-server-integration.settings', new class extends Component
        {
            public Server $server;

            public function render(): string
            {
                return '<div>Fake integration for {{ $server->name }}</div>';
            }
        });
    }

    public function id(): string
    {
        return 'fake-server-integration';
    }

    public function settingsComponent(): string
    {
        return 'fake-server-integration.settings';
    }

    public function secrets(Server $server): array
    {
        return $this->storedSecrets;
    }
}
