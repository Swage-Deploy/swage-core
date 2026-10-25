<?php

use App\Models\Server;
use App\Models\Site;
use App\Plugins\PluginCategory;
use App\Sites\SiteIntegrations;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public function mount(): void
    {
        $this->authorize('view', $this->site);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Plugins').' · '.$this->site->domain);
    }
}; ?>

<div>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            @forelse (app(SiteIntegrations::class)->all() as $integration)
                @livewire($integration->settingsComponent(), ['site' => $site], key('integration-'.$integration->id()))
            @empty
                <x-panel icon="puzzle-piece" :heading="__('Plugins')">
                    <flux:text>{{ __('No site plugins are installed. Site plugins connect sites to other services, such as monitoring, and are set up here.') }}</flux:text>
                    @can('administer')
                        <flux:button size="sm" icon="squares-plus" class="mt-4" :href="route('plugins', PluginCategory::Site->value)" wire:navigate>{{ __('Browse site plugins') }}</flux:button>
                    @endcan
                </x-panel>
            @endforelse
        </div>
    </x-pages::sites.layout>
</div>
