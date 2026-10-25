<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServerTuning;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Servers')] class extends Component {
    /**
     * @return Collection<int, Server>
     */
    #[Computed]
    public function servers(): Collection
    {
        return Auth::user()->accessibleServers()->withCount('sites')->latest()->get();
    }

    /**
     * Sites the user was added to on servers they cannot open themselves.
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function sharedSites(): Collection
    {
        return Auth::user()->sharedSites()->with('server:id,name')->orderBy('domain')->get();
    }

    #[Computed]
    public function hasProvisioningServers(): bool
    {
        return $this->servers->contains(fn (Server $server): bool => $server->status === ServerStatus::Provisioning);
    }
}; ?>

<div class="flex flex-col gap-8" @if ($this->hasProvisioningServers) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Servers') }}</flux:heading>
            <flux:subheading>{{ __('Servers provisioned and managed by Swage.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('servers.create')" wire:navigate>{{ __('New server') }}</flux:button>
    </div>

    @if ($this->sharedSites->isNotEmpty())
        <x-panel icon="globe-alt" :heading="__('Shared sites')" :description="__('Sites you were added to on servers you cannot open.')" flush>
            <flux:table>
                <flux:table.rows>
                    @foreach ($this->sharedSites as $site)
                        <flux:table.row :key="'shared-site-'.$site->id">
                            <flux:table.cell class="font-medium">
                                <flux:link :href="route('servers.sites.show', [$site->server, $site])" wire:navigate>{{ $site->domain }}</flux:link>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $site->server->name }}</flux:table.cell>
                            <flux:table.cell align="end"><x-status :color="$site->status->color()" :label="$site->status->label()" /></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-panel>
    @endif

    @if ($this->servers->isEmpty() && $this->sharedSites->isEmpty())
        <x-panel>
            <x-empty-state icon="server-stack" :heading="__('No servers yet')">
                {{ __('Connect a fresh Ubuntu 24.04 server and Swage will install and tune everything for its hardware.') }}
                <x-slot:actions>
                    <flux:button variant="primary" icon="plus" :href="route('servers.create')" wire:navigate>{{ __('Connect a server') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        </x-panel>
    @elseif ($this->servers->isNotEmpty())
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-zinc-200 bg-zinc-200 lg:grid-cols-4 dark:border-white/8 dark:bg-white/8">
            @foreach ([
                [__('Servers'), $this->servers->count(), $this->servers->where('status', ServerStatus::Active)->count().' '.__('active')],
                [__('Sites'), $this->servers->sum('sites_count'), __('across every server')],
                [__('vCPUs'), $this->servers->sum('cpu_cores'), __('provisioned cores')],
                [__('Memory'), Number::fileSize($this->servers->sum('memory_mb') * 1024 * 1024), __('tuned for workloads')],
            ] as [$label, $value, $hint])
                <div class="bg-white px-5 py-4 dark:bg-zinc-900">
                    <dt class="text-xs font-medium tracking-wider text-zinc-500 uppercase">{{ $label }}</dt>
                    <dd class="mt-1 font-mono text-2xl font-medium text-zinc-900 dark:text-white">{{ $value }}</dd>
                    <dd class="text-xs text-zinc-500">{{ $hint }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($this->servers as $server)
                <a href="{{ route('servers.show', $server) }}" wire:navigate wire:key="server-{{ $server->id }}" class="group relative flex flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:border-ember-500/50 hover:shadow-lg hover:shadow-ember-500/5 dark:border-white/8 dark:bg-zinc-900/70 dark:hover:border-ember-500/40">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200 transition group-hover:bg-ember-500/10 group-hover:text-ember-500 group-hover:ring-ember-500/30 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10">
                                <flux:icon.server class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $server->name }}</div>
                                <div class="font-mono text-xs text-zinc-500">{{ $server->ip_address }}</div>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <x-status :color="$server->status->color()" :label="$server->status->label()" />
                            @if ($server->user_id !== auth()->id())
                                <flux:badge size="sm" color="zinc">{{ __('Shared') }}</flux:badge>
                            @endif
                        </div>
                    </div>

                    @if ($server->cpu_cores)
                        <div class="grid grid-cols-3 gap-2 text-center">
                            @foreach ([
                                ['cpu-chip', trans_choice(':count vCPU|:count vCPUs', $server->cpu_cores)],
                                ['circle-stack', Number::fileSize($server->memory_mb * 1024 * 1024)],
                                ['archive-box', $server->disk_gb.' GB'],
                            ] as [$icon, $value])
                                <div class="flex items-center justify-center gap-1.5 rounded-lg bg-zinc-50 px-2 py-2 font-mono text-xs text-zinc-700 dark:bg-white/4 dark:text-zinc-300">
                                    <flux:icon :name="$icon" variant="micro" class="text-zinc-400" />
                                    {{ $value }}
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="flex items-center gap-2 rounded-lg border border-dashed border-zinc-300 px-3 py-2.5 text-xs text-zinc-500 dark:border-white/10">
                            @if ($server->status === ServerStatus::Provisioning)
                                <flux:icon.arrow-path variant="micro" class="animate-spin text-ember-500" />
                                {{ $server->provisioning_step ?? __('Provisioning') }}
                            @else
                                <flux:icon.command-line variant="micro" />
                                {{ __('Waiting for the provisioning command to run') }}
                            @endif
                        </div>
                    @endif

                    @if ($server->tuning && $server->memory_mb)
                        <x-memory-split :allocation="ServerTuning::fromArray($server->tuning)->memoryAllocation($server->memory_mb)" :memory-mb="$server->memory_mb" compact />
                    @endif

                    <div class="mt-auto flex items-center justify-between border-t border-zinc-100 pt-4 text-xs text-zinc-500 dark:border-white/5">
                        @if ($server->isAppServer())
                            <span class="flex items-center gap-1.5">
                                <flux:icon.globe-alt variant="micro" />
                                {{ trans_choice(':count site|:count sites', $server->sites_count) }}
                            </span>
                        @else
                            <span class="flex items-center gap-1.5">
                                <flux:icon.circle-stack variant="micro" />
                                {{ collect($server->databaseEngines())->map->label()->implode(' & ') ?: __('Database server') }}
                            </span>
                        @endif
                        <span class="flex items-center gap-1">
                            @foreach ($server->php_versions as $version)
                                <span @class([
                                    'rounded px-1.5 py-0.5 font-mono',
                                    'bg-ember-500/10 text-ember-700 dark:text-ember-400' => $version === $server->default_php_version,
                                    'bg-zinc-100 dark:bg-white/5' => $version !== $server->default_php_version,
                                ])>{{ $version->value }}</span>
                            @endforeach
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
