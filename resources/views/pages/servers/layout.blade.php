@props(['server', 'heading' => null, 'subheading' => null])

<div class="flex flex-col gap-6">
    <div class="relative isolate overflow-hidden rounded-2xl border border-zinc-200 bg-white px-6 pt-5 shadow-xs dark:border-white/8 dark:bg-zinc-900/70">
        <div class="provision-grid absolute inset-0 -z-10 text-zinc-900 dark:text-white"></div>
        <div class="absolute -top-24 -right-24 -z-10 size-64 rounded-full bg-ember-500/10 blur-3xl"></div>

        <flux:breadcrumbs class="mb-4">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Servers') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $server->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="flex min-w-0 items-center gap-4">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-ember-400 to-ember-700 text-zinc-950 shadow-[0_0_24px_-6px] shadow-ember-500/70">
                    <flux:icon.server class="size-6" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:heading size="xl" level="1" class="truncate tracking-tight">{{ $server->name }}</flux:heading>
                        <x-status :color="$server->status->color()" :label="$server->status->label()" />
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs text-zinc-500">
                        <span x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText('{{ $server->ip_address }}'); copied = true; setTimeout(() => copied = false, 1500)" class="flex cursor-pointer items-center gap-1 hover:text-zinc-800 dark:hover:text-zinc-200" title="{{ __('Copy IP address') }}">
                            <flux:icon.globe-alt variant="micro" />
                            {{ $server->ip_address }}
                            <flux:icon.check variant="micro" class="text-emerald-500" x-show="copied" x-cloak />
                        </span>
                        @if ($server->cpu_cores)
                            <span class="flex items-center gap-1"><flux:icon.cpu-chip variant="micro" />{{ trans_choice(':count vCPU|:count vCPUs', $server->cpu_cores) }}</span>
                            <span class="flex items-center gap-1"><flux:icon.circle-stack variant="micro" />{{ Number::fileSize($server->memory_mb * 1024 * 1024) }}</span>
                            <span class="flex items-center gap-1"><flux:icon.archive-box variant="micro" />{{ $server->disk_gb }} GB</span>
                        @endif
                        @if ($server->isAppServer())
                            <span class="flex items-center gap-1"><flux:icon.code-bracket variant="micro" />PHP {{ $server->default_php_version->value }}</span>
                        @else
                            <span class="flex items-center gap-1"><flux:icon.circle-stack variant="micro" />{{ __('Database server') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <flux:navbar scrollable aria-label="{{ __('Server') }}" class="-mb-px mt-5 -ms-2">
            <flux:navbar.item icon="squares-2x2" :href="route('servers.show', $server)" :current="request()->pageRouteIs('servers.show')" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
            @if ($server->isAppServer())
                <flux:navbar.item icon="globe-alt" :href="route('servers.sites', $server)" :current="request()->pageRouteIs('servers.sites', 'servers.sites.*')" wire:navigate>{{ __('Sites') }}</flux:navbar.item>
            @endif
            @if ($server->hasDatabase())
                <flux:navbar.item icon="circle-stack" :href="route('servers.databases', $server)" :current="request()->pageRouteIs('servers.databases')" wire:navigate>{{ __('Databases') }}</flux:navbar.item>
            @endif
            <flux:navbar.item icon="cog-6-tooth" :href="route('servers.daemons', $server)" :current="request()->pageRouteIs('servers.daemons')" wire:navigate>{{ __('Daemons') }}</flux:navbar.item>
            <flux:navbar.item icon="clock" :href="route('servers.scheduler', $server)" :current="request()->pageRouteIs('servers.scheduler')" wire:navigate>{{ __('Scheduler') }}</flux:navbar.item>
            <flux:navbar.item icon="adjustments-horizontal" :href="route('servers.config', $server)" :current="request()->pageRouteIs('servers.config')" wire:navigate>{{ __('Config') }}</flux:navbar.item>
            <flux:navbar.item icon="user-group" :href="route('servers.members', $server)" :current="request()->pageRouteIs('servers.members')" wire:navigate>{{ __('Members') }}</flux:navbar.item>
            @php($attention = App\Servers\ServerAttention::combine(app(App\Servers\ServerAttention::class)->forServer($server)))
            <flux:navbar.item icon="puzzle-piece" :href="route('servers.plugins', $server)" :current="request()->pageRouteIs('servers.plugins')" wire:navigate
                :badge="$attention?->count" :badge:color="$attention?->color()" :title="$attention?->label">{{ __('Plugins') }}</flux:navbar.item>
            <flux:navbar.item icon="document-magnifying-glass" :href="route('servers.logs', $server)" :current="request()->pageRouteIs('servers.logs')" wire:navigate>{{ __('Logs') }}</flux:navbar.item>
            <flux:navbar.item icon="queue-list" :href="route('servers.activity', $server)" :current="request()->pageRouteIs('servers.activity')" wire:navigate>{{ __('Activity') }}</flux:navbar.item>
        </flux:navbar>
    </div>

    <div class="min-w-0">
        @if ($heading || $subheading)
            <div class="mb-5">
                @if ($heading)
                    <flux:heading size="lg" class="tracking-tight">{{ $heading }}</flux:heading>
                @endif
                @if ($subheading)
                    <flux:subheading>{{ $subheading }}</flux:subheading>
                @endif
            </div>
        @endif

        @if (! $server->isActive() && ! request()->pageRouteIs('servers.show', 'servers.activity'))
            <flux:callout icon="information-circle" color="amber">
                <flux:callout.text>{{ __('This server is not provisioned yet. Finish provisioning from the overview page before managing it.') }}</flux:callout.text>
            </flux:callout>
        @else
            {{ $slot }}
        @endif
    </div>
</div>
