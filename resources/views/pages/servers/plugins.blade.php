<?php

use App\Models\Server;
use App\Plugins\PluginCategory;
use App\Servers\ServerIntegrations;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Plugins').' · '.$this->server->name);
    }
}; ?>

<x-pages::servers.layout :server="$server">
    <div class="flex flex-col gap-6">
        @forelse (app(ServerIntegrations::class)->all() as $integration)
            @livewire($integration->settingsComponent(), ['server' => $server], key('integration-'.$integration->id()))
        @empty
            <x-panel icon="puzzle-piece" :heading="__('Plugins')">
                <flux:text>{{ __('No server plugins are installed. Server plugins add services each server can be set up with, and are set up here.') }}</flux:text>
                @can('administer')
                    <flux:button size="sm" icon="squares-plus" class="mt-4" :href="route('plugins', PluginCategory::Server->value)" wire:navigate>{{ __('Browse server plugins') }}</flux:button>
                @endcan
            </x-panel>
        @endforelse
    </div>
</x-pages::servers.layout>
