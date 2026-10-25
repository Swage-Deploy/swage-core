@props(['site', 'heading' => null, 'subheading' => null])

@php($server = $site->server)

<div class="flex flex-col gap-6">
    <div class="relative isolate overflow-hidden rounded-2xl border border-zinc-200 bg-white px-6 pt-5 shadow-xs dark:border-white/8 dark:bg-zinc-900/70">
        <div class="provision-grid absolute inset-0 -z-10 text-zinc-900 dark:text-white"></div>
        <div class="absolute -top-24 -right-24 -z-10 size-64 rounded-full bg-ember-500/10 blur-3xl"></div>

        <flux:breadcrumbs class="mb-4">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Servers') }}</flux:breadcrumbs.item>
            @can('view', $server)
                <flux:breadcrumbs.item :href="route('servers.sites', $server)" wire:navigate>{{ $server->name }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item>{{ $server->name }}</flux:breadcrumbs.item>
            @endcan
            <flux:breadcrumbs.item>{{ $site->domain }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex min-w-0 items-center gap-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-ember-400 to-ember-700 text-zinc-950 shadow-[0_0_24px_-6px] shadow-ember-500/70">
                <flux:icon.globe-alt class="size-6" />
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="xl" level="1" class="truncate tracking-tight">{{ $site->domain }}</flux:heading>
                    <x-status :color="$site->status->color()" :label="$site->status->label()" />
                    @if ($site->zero_downtime)
                        <flux:badge size="sm" color="sky" icon="bolt">{{ __('Zero downtime') }}</flux:badge>
                    @endif
                    @if ($site->octane)
                        <flux:badge size="sm" color="violet" icon="rocket-launch">Octane</flux:badge>
                    @endif
                    @if ($site->isIsolated())
                        <flux:badge size="sm" color="emerald" icon="shield-check">{{ __('Isolated') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs text-zinc-500">
                    <a href="{{ ($site->certificate?->isActive() ? 'https://' : 'http://').$site->domain }}" target="_blank" class="flex items-center gap-1 hover:text-ember-600 dark:hover:text-ember-400">
                        @if ($site->certificate?->isActive())
                            <flux:tooltip :content="__('SSL certificate active')">
                                <flux:icon.lock-closed variant="micro" class="text-emerald-500" />
                            </flux:tooltip>
                        @else
                            <flux:tooltip :content="__('No SSL certificate')">
                                <flux:icon.lock-open variant="micro" />
                            </flux:tooltip>
                        @endif
                        {{ $site->domain }}
                        <flux:icon.arrow-top-right-on-square variant="micro" />
                    </a>
                    <span class="flex items-center gap-1"><flux:icon.code-bracket variant="micro" />PHP {{ $site->php_version->value }}</span>
                    <span class="flex items-center gap-1" title="{{ __('System user') }}"><flux:icon.user variant="micro" />{{ $site->systemUser() }}</span>
                    @if ($site->repository)
                        <span class="flex items-center gap-1"><flux:icon.folder-git-2 variant="micro" class="size-4" />{{ $site->repository }}<span class="text-ember-600 dark:text-ember-400">:{{ $site->branch }}</span></span>
                    @endif
                </div>
            </div>
        </div>

        <flux:navbar scrollable aria-label="{{ __('Site') }}" class="-mb-px mt-5 -ms-2">
            <flux:navbar.item icon="rocket-launch" :href="route('servers.sites.show', [$server, $site])" :current="request()->pageRouteIs('servers.sites.show')" wire:navigate>{{ __('Deployments') }}</flux:navbar.item>
            <flux:navbar.item icon="lock-closed" :href="route('servers.sites.ssl', [$server, $site])" :current="request()->pageRouteIs('servers.sites.ssl')" wire:navigate>{{ __('SSL') }}</flux:navbar.item>
            <flux:navbar.item icon="queue-list" :href="route('servers.sites.queues', [$server, $site])" :current="request()->pageRouteIs('servers.sites.queues')" wire:navigate>{{ __('Queues & scheduler') }}</flux:navbar.item>
            <flux:navbar.item icon="code-bracket-square" :href="route('servers.sites.nginx', [$server, $site])" :current="request()->pageRouteIs('servers.sites.nginx')" wire:navigate>nginx</flux:navbar.item>
            <flux:navbar.item icon="cpu-chip" :href="route('servers.sites.php', [$server, $site])" :current="request()->pageRouteIs('servers.sites.php')" wire:navigate>PHP</flux:navbar.item>
            @can('update', $site)
                <flux:navbar.item icon="command-line" :href="route('servers.sites.commands', [$server, $site])" :current="request()->pageRouteIs('servers.sites.commands')" wire:navigate>{{ __('Commands') }}</flux:navbar.item>
            @endcan
            <flux:navbar.item icon="document-magnifying-glass" :href="route('servers.sites.logs', [$server, $site])" :current="request()->pageRouteIs('servers.sites.logs')" wire:navigate>{{ __('Logs') }}</flux:navbar.item>
            <flux:navbar.item icon="user-group" :href="route('servers.sites.members', [$server, $site])" :current="request()->pageRouteIs('servers.sites.members')" wire:navigate>{{ __('Members') }}</flux:navbar.item>
            <flux:navbar.item icon="puzzle-piece" :href="route('servers.sites.plugins', [$server, $site])" :current="request()->pageRouteIs('servers.sites.plugins')" wire:navigate>{{ __('Plugins') }}</flux:navbar.item>
            <flux:navbar.item icon="cog-6-tooth" :href="route('servers.sites.settings', [$server, $site])" :current="request()->pageRouteIs('servers.sites.settings')" wire:navigate>{{ __('Settings') }}</flux:navbar.item>
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

        {{ $slot }}
    </div>
</div>
