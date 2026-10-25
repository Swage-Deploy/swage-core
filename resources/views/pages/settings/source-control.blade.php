<?php

use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Source control')] class extends Component {
    public function mount(): void
    {
        if ($provider = session('source-control-connected')) {
            Flux::toast(variant: 'success', text: __(':provider connected.', ['provider' => $provider]));
        }

        if ($error = session('source-control-error')) {
            Flux::toast(variant: 'danger', text: $error);
        }
    }

    /**
     * @return array<string, SourceControlProvider>
     */
    #[Computed]
    public function providers(): array
    {
        return app(SourceControlProviders::class)->all();
    }

    /**
     * The user's connections, keyed by provider.
     */
    #[Computed]
    public function connections(): Collection
    {
        return Auth::user()->sourceControlConnections()->get()->keyBy('provider');
    }

    /**
     * Forget the stored credentials for a provider.
     */
    public function disconnect(string $provider): void
    {
        Auth::user()->sourceControlConnections()->where('provider', $provider)->delete();

        unset($this->connections);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Source control') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Source control')" :subheading="__('Connect the git hosts your repositories live on, so Swage can list them, add deploy keys and enable quick deploy. Repositories elsewhere can be installed from a clone URL instead.')">
        <div class="flex flex-col gap-4">
            @forelse ($this->providers as $provider)
                @php($connection = $this->connections->get($provider->id()))
                <div wire:key="provider-{{ $provider->id() }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <flux:icon.folder-git-2 @class(['size-5', 'text-emerald-500' => $connection, 'text-zinc-400' => ! $connection]) />
                            <div>
                                <flux:heading>{{ $provider->name() }}</flux:heading>
                                @if ($connection)
                                    <flux:text>{!! __('Connected as :username.', ['username' => '<span class="font-mono font-medium text-zinc-900 dark:text-white">'.e($connection->username).'</span>']) !!}</flux:text>
                                @endif
                            </div>
                        </div>

                        @if ($connection)
                            <flux:button size="sm" variant="danger" wire:click="disconnect('{{ $provider->id() }}')" wire:confirm.danger="{{ __('Disconnect :provider? Existing deploy keys keep working, but repositories cannot be installed and quick deploy cannot be changed until you reconnect.', ['provider' => $provider->name()]) }}">{{ __('Disconnect') }}</flux:button>
                        @elseif ($provider->isConfigured())
                            <flux:button size="sm" variant="primary" icon="link" :href="route('source-control.redirect', $provider->id())">{{ __('Connect :provider', ['provider' => $provider->name()]) }}</flux:button>
                        @endif
                    </div>

                    @if (! $connection && ! $provider->isConfigured())
                        <flux:callout icon="exclamation-triangle" color="amber">
                            <flux:callout.text>{{ __(':provider needs to be configured by an administrator before accounts can be connected.', ['provider' => $provider->name()]) }}</flux:callout.text>
                            @if ($provider instanceof App\SourceControl\HasSettings)
                                @can('administer')
                                    <x-slot name="actions">
                                        <flux:button size="sm" :href="route('source-control.configure', $provider->id())" wire:navigate>{{ __('Configure') }}</flux:button>
                                    </x-slot>
                                @endcan
                            @endif
                        </flux:callout>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No source control providers are installed yet. An administrator can install them, such as GitHub, from the Plugins page. Repositories on any git host can still be installed from a clone URL.') }}</flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>
</section>
