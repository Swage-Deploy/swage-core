<?php

use App\Livewire\Concerns\ViewsLogs;
use App\Models\Server;
use App\Models\Site;
use App\Ssh\LogFiles;
use Livewire\Component;

new class extends Component {
    use ViewsLogs;

    public Server $server;

    public Site $site;

    public function mount(): void
    {
        $this->authorize('view', $this->site);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Logs').' · '.$this->site->domain);
    }

    protected function logsBelongTo(): Site
    {
        return $this->site;
    }

    protected function logFiles(): array
    {
        return LogFiles::forSite($this->site);
    }
}; ?>

<x-pages::sites.layout :site="$site">
    <x-panel icon="document-magnifying-glass" :heading="__('Logs')" :description="__('Read live from the server. Known secrets are masked.')">
        @include('partials.log-viewer')
    </x-panel>
</x-pages::sites.layout>
