<?php

use App\Models\Server;
use App\Plugins\PluginCategory;
use App\Servers\ServerIntegration;
use App\Servers\ServerIntegrations;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Server $server;

    /**
     * The id of the integration whose tab is open. The first is open when it names none that is installed.
     */
    #[Url(except: '')]
    public string $plugin = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    /**
     * @return array<string, ServerIntegration>
     */
    #[Computed]
    public function integrations(): array
    {
        return app(ServerIntegrations::class)->all();
    }

    #[Computed]
    public function current(): ?ServerIntegration
    {
        return $this->integrations[$this->plugin] ?? collect($this->integrations)->first();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Plugins').' · '.$this->server->name);
    }
}; ?>

<x-pages::servers.layout :server="$server">
    <div class="flex flex-col gap-6">
        @if ($integration = $this->current)
            <x-plugin-tabs :integrations="$this->integrations" :current="$integration->id()" />

            @livewire($integration->settingsComponent(), ['server' => $server], key('integration-'.$integration->id()))
        @else
            <x-panel icon="puzzle-piece" :heading="__('Plugins')">
                <flux:text>{{ __('No server plugins are installed. Server plugins add services each server can be set up with, and are set up here.') }}</flux:text>
                @can('administer')
                    <flux:button size="sm" icon="squares-plus" class="mt-4" :href="route('plugins', PluginCategory::Server->value)" wire:navigate>{{ __('Browse server plugins') }}</flux:button>
                @endcan
            </x-panel>
        @endif
    </div>
</x-pages::servers.layout>
