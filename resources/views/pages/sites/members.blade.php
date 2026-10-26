<?php

use App\Livewire\Concerns\ManagesMembers;
use App\Models\Server;
use App\Models\Site;
use Livewire\Component;

new class extends Component {
    use ManagesMembers;

    public Server $server;

    public Site $site;

    public function mount(): void
    {
        $this->authorize('view', $this->site);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Members').' · '.$this->site->domain);
    }

    protected function membersOf(): Site
    {
        return $this->site;
    }
}; ?>

<div>
    <x-pages::sites.layout :site="$site">
        <x-shared-site-warning :site="$site" class="mb-6" />

        @include('partials.members', [
            'owner' => $server->user,
            'inherited' => $server->members()->orderBy('name')->get(),
            'canManage' => auth()->user()->can('manageMembers', $site),
            'description' => __('People with access to just this site. Its server\'s owner and members also have access.'),
        ])
    </x-pages::sites.layout>
</div>
