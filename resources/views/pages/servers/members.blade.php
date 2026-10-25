<?php

use App\Livewire\Concerns\ManagesMembers;
use App\Models\Server;
use Livewire\Component;

new class extends Component {
    use ManagesMembers;

    public Server $server;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Members').' · '.$this->server->name);
    }

    protected function membersOf(): Server
    {
        return $this->server;
    }
}; ?>

<x-pages::servers.layout :server="$server">
    @include('partials.members', [
        'owner' => $server->user,
        'canManage' => auth()->user()->can('manageMembers', $server),
        'description' => __('People with access to this server and every site on it, besides its owner. To give someone just one site, add them on that site\'s Members tab.'),
    ])
</x-pages::servers.layout>
