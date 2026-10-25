<?php

use App\Livewire\Concerns\ViewsLogs;
use App\Models\Server;
use App\Ssh\LogFiles;
use Livewire\Component;

new class extends Component {
    use ViewsLogs;

    public Server $server;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Logs').' · '.$this->server->name);
    }

    protected function logsBelongTo(): Server
    {
        return $this->server;
    }

    protected function logFiles(): array
    {
        return LogFiles::forServer($this->server);
    }
}; ?>

<x-pages::servers.layout :server="$server">
    <x-panel icon="document-magnifying-glass" :heading="__('Logs')" :description="__('Read live from the server. Known secrets are masked. Site logs, such as laravel.log, are on each site\'s Logs tab.')">
        @include('partials.log-viewer')
    </x-panel>
</x-pages::servers.layout>
