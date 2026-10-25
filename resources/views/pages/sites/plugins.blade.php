<?php

use App\Models\Server;
use App\Models\Site;
use App\Plugins\PluginCategory;
use App\Sites\SiteIntegration;
use App\Sites\SiteIntegrations;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    /**
     * The id of the integration whose tab is open. The first is open when it names none that is installed.
     */
    #[Url(except: '')]
    public string $plugin = '';

    public function mount(): void
    {
        $this->authorize('view', $this->site);
    }

    /**
     * @return array<string, SiteIntegration>
     */
    #[Computed]
    public function integrations(): array
    {
        return app(SiteIntegrations::class)->all();
    }

    #[Computed]
    public function current(): ?SiteIntegration
    {
        return $this->integrations[$this->plugin] ?? collect($this->integrations)->first();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Plugins').' · '.$this->site->domain);
    }
}; ?>

<div>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            @if ($integration = $this->current)
                <x-plugin-tabs :integrations="$this->integrations" :current="$integration->id()" />

                @livewire($integration->settingsComponent(), ['site' => $site], key('integration-'.$integration->id()))
            @else
                <x-panel icon="puzzle-piece" :heading="__('Plugins')">
                    <flux:text>{{ __('No site plugins are installed. Site plugins connect sites to other services, such as monitoring, and are set up here.') }}</flux:text>
                    @can('administer')
                        <flux:button size="sm" icon="squares-plus" class="mt-4" :href="route('plugins', PluginCategory::Site->value)" wire:navigate>{{ __('Browse site plugins') }}</flux:button>
                    @endcan
                </x-panel>
            @endif
        </div>
    </x-pages::sites.layout>
</div>
