<?php

use App\Actions\Servers\RemoveServer;
use App\Enums\DatabaseEngine;
use App\Enums\ServerStatus;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\InstallPgvector;
use App\Jobs\OptimizeServer;
use App\Jobs\RebootServer;
use App\Models\Certificate;
use App\Models\Server;
use App\Provisioning\ServerTuning;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public string $confirmation = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->server->name);
    }

    #[Computed]
    public function tuning(): ?ServerTuning
    {
        return $this->server->tuning ? ServerTuning::fromArray($this->server->tuning) : null;
    }

    /**
     * Certificates on the server's sites that certbot should have renewed by now.
     *
     * @return Collection<int, Certificate>
     */
    #[Computed]
    public function overdueCertificates(): Collection
    {
        return Certificate::query()->renewalOverdue()
            ->whereHas('site', fn ($query) => $query->where('server_id', $this->server->id))
            ->with('site')
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Reboot the server in the background, such as after an update that needs one.
     */
    public function reboot(): void
    {
        $this->authorize('update', $this->server);

        RebootServer::dispatch($this->server);

        Flux::toast(variant: 'success', text: __('Rebooting the server. Sites are unavailable for a minute or two while it starts again.'));
    }

    /**
     * Give up on a provisioning run that stopped reporting progress, so it can be started again with a new command.
     */
    public function restartProvisioning(): void
    {
        $this->authorize('update', $this->server);

        abort_unless($this->server->provisioningHasStalled(), 409);

        $this->server->update([
            'status' => ServerStatus::Failed,
            'provisioning_step' => "{$this->server->provisioning_step} (stopped responding)",
            'provision_token' => Str::random(64),
        ]);
    }

    /**
     * Re-detect the hardware and rewrite every service config to match it.
     */
    public function optimize(): void
    {
        $this->authorize('update', $this->server);

        OptimizeServer::dispatch($this->server);

        Flux::toast(variant: 'success', text: __('Re-optimizing services. Follow along in the activity log.'));
    }

    /**
     * Install a database engine the server was provisioned without.
     */
    public function installDatabaseEngine(string $engine): void
    {
        $this->authorize('update', $this->server);

        $engine = DatabaseEngine::from($engine);

        abort_unless($this->server->isActive() && ! $this->server->runsDatabaseEngine($engine), 409);

        if (! $this->claimDatabaseInstall($engine->value)) {
            return;
        }

        InstallDatabaseEngine::dispatch($this->server, $engine);

        Flux::toast(variant: 'success', text: __('Installing :engine. Follow along in the activity log.', ['engine' => $engine->label()]));
    }

    /**
     * Add pgvector to the server's PostgreSQL.
     */
    public function installPgvector(): void
    {
        $this->authorize('update', $this->server);

        abort_unless($this->server->isActive() && $this->server->usesPostgres() && ! $this->server->has_pgvector, 409);

        if (! $this->claimDatabaseInstall('pgvector')) {
            return;
        }

        InstallPgvector::dispatch($this->server);

        Flux::toast(variant: 'success', text: __('Installing pgvector. Follow along in the activity log.'));
    }

    /**
     * Mark the server as installing a database, unless another install is already running.
     */
    protected function claimDatabaseInstall(string $install): bool
    {
        $claimed = Server::query()->whereKey($this->server->id)->whereNull('installing_database')->update(['installing_database' => $install]) === 1;

        $this->server->refresh();

        if (! $claimed) {
            Flux::toast(variant: 'warning', text: __('Another database install is already running on this server.'));
        }

        return $claimed;
    }

    /**
     * Stop managing the server. The machine itself is left running.
     */
    public function delete(RemoveServer $removeServer): void
    {
        $this->authorize('delete', $this->server);

        $this->validate(['confirmation' => ['required', 'in:'.$this->server->name]], [
            'confirmation.in' => __('Type the server name to confirm.'),
        ]);

        $removeServer->handle($this->server);

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div @if (in_array($server->status, [ServerStatus::Pending, ServerStatus::Provisioning]) || $server->installing_database) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        <div class="flex flex-col gap-6">
            @if (in_array($server->status, [ServerStatus::Pending, ServerStatus::Failed]))
                <x-panel icon="command-line" :heading="__('Provision this server')" :description="__('SSH into the fresh Ubuntu 24.04 server as root and run the command below. It reports the hardware to Swage, then installs and tunes every service for it.')">
                    <div class="flex flex-col gap-4">
                        @if ($server->status === ServerStatus::Failed)
                            <flux:callout icon="x-circle" color="red">
                                <flux:callout.heading>{{ __('Provisioning failed') }}</flux:callout.heading>
                                <flux:callout.text>{{ $server->provisioning_step }}. {{ __('Check /root/swage-provision.log on the server, then run the command again.') }}</flux:callout.text>
                            </flux:callout>
                        @endif

                        @can('update', $server)
                            @unless ($server->provisionsOverHttps())
                                <flux:callout icon="exclamation-triangle" color="amber">
                                    <flux:callout.heading>{{ __('Swage is not served over HTTPS') }}</flux:callout.heading>
                                    <flux:callout.text>{{ __('The server would fetch its provisioning scripts, which run as root, and report its SSH host key over plain HTTP, where anyone on the way could change them. Serve Swage over HTTPS and set APP_URL to its https:// address before provisioning.') }}</flux:callout.text>
                                </flux:callout>
                            @endunless
                            <flux:input readonly copyable :value="$server->provisionCommand()" class="font-mono" />
                        @else
                            <flux:text>{{ __('The server\'s owner or a manager can see the command.') }}</flux:text>
                        @endcan

                        <x-provisioning-steps :server="$server" class="mt-2 max-w-xl" />
                    </div>
                </x-panel>
            @elseif ($server->status === ServerStatus::Provisioning)
                <div class="relative overflow-hidden rounded-xl border border-ember-500/30 bg-ember-500/5 p-5">
                    <div class="flex items-center gap-4">
                        <span class="relative flex size-10 items-center justify-center">
                            <span class="absolute inset-0 animate-ping rounded-full bg-ember-500/20"></span>
                            <flux:icon.fire class="size-6 text-ember-500" />
                        </span>
                        <div>
                            <flux:heading>{{ __('Provisioning your server') }}</flux:heading>
                            <flux:text class="font-mono text-xs">{{ $server->provisioning_step }}</flux:text>
                        </div>
                    </div>
                    <x-provisioning-steps :server="$server" class="mt-5 max-w-xl" />

                    @if ($server->provisioningHasStalled())
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:text>{{ __('The server has not reported progress for :minutes minutes. If it was rebooted or the script was stopped, start over with a new command.', ['minutes' => Server::PROVISIONING_STALL_MINUTES]) }}</flux:text>
                            <flux:button size="sm" wire:click="restartProvisioning" wire:confirm="{{ __('Start provisioning over? Only do this if the script is no longer running on the server.') }}">{{ __('Start over') }}</flux:button>
                        </div>
                    @endif
                    <div class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden bg-ember-500/10">
                        <div class="h-full w-1/3 animate-[ember-slide_1.6s_ease-in-out_infinite] bg-linear-to-r from-transparent via-ember-500 to-transparent"></div>
                    </div>
                </div>
            @endif

            @if ($this->overdueCertificates->isNotEmpty())
                <x-panel icon="exclamation-triangle" :heading="__('Certificates not renewing')" :description="__('Certbot renews certificates 30 days before they expire, so these have failed to renew. Each site\'s SSL tab explains what to check.')">
                    <div class="flex flex-col gap-2">
                        @foreach ($this->overdueCertificates as $certificate)
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <flux:link :href="route('servers.sites.ssl', [$server, $certificate->site])" wire:navigate class="font-mono text-sm">{{ $certificate->site->domain }}</flux:link>
                                <flux:text>{{ $certificate->expires_at->isPast() ? __('Expired :time', ['time' => $certificate->expires_at->diffForHumans()]) : __('Expires :time', ['time' => $certificate->expires_at->diffForHumans()]) }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </x-panel>
            @endif

            @if ($server->isActive() && $server->reboot_required_at)
                <x-panel icon="arrow-path" :heading="__('Reboot required')" :description="__('Automatic updates installed changes that only take effect after a reboot. Swage never reboots servers on its own. Sites are unavailable for a minute or two while the server starts again.')">
                    <x-slot:actions>
                        <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="reboot" wire:confirm="{{ __('Reboot :name now? Its sites are unavailable until it starts again.', ['name' => $server->name]) }}">{{ __('Reboot now') }}</flux:button>
                    </x-slot:actions>

                    <flux:text>
                        {{ __('First noticed :time.', ['time' => $server->reboot_required_at->diffForHumans()]) }}
                        @if ($server->reboot_required_packages)
                            {{ __('Requested by :packages.', ['packages' => implode(', ', $server->reboot_required_packages)]) }}
                        @endif
                    </flux:text>
                </x-panel>
            @endif

            @if ($this->tuning)
                @php($tuning = $this->tuning)

                <x-panel icon="adjustments-horizontal" :heading="__('Memory allocation')" :description="__('How Swage divided :memory between services, calculated from this server\'s hardware. Re-optimize after resizing the server.', ['memory' => Number::fileSize($server->memory_mb * 1024 * 1024, precision: 1)])">
                    @if ($server->isActive())
                        <x-slot:actions>
                            <flux:button size="sm" icon="bolt" wire:click="optimize">{{ __('Re-optimize') }}</flux:button>
                        </x-slot:actions>
                    @endif

                    <x-memory-split :allocation="$tuning->memoryAllocation($server->memory_mb)" :memory-mb="$server->memory_mb" />
                </x-panel>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach (array_filter([
                        $tuning->php ? ['code-bracket', 'PHP-FPM', [
                            __('Memory budget') => $tuning->php['memory_budget_mb'].' MB',
                            'pm.max_children' => $tuning->php['primary_pool']['max_children'],
                            'pm.start_servers' => $tuning->php['primary_pool']['start_servers'],
                            'pm.min / max spare' => $tuning->php['primary_pool']['min_spare_servers'].' / '.$tuning->php['primary_pool']['max_spare_servers'],
                            __('Other versions') => 'ondemand, '.$tuning->php['secondary_pool']['max_children'].' max',
                            'opcache.memory_consumption' => $tuning->php['opcache_memory_mb'].' MB',
                            ...(isset($tuning->php['octane_workers']) ? [__('Octane workers') => $tuning->php['octane_workers']] : []),
                        ]] : null,
                        $tuning->nginx ? ['globe-alt', 'nginx', [
                            'worker_processes' => $tuning->nginx['worker_processes'],
                            'worker_connections' => $tuning->nginx['worker_connections'],
                            'worker_rlimit_nofile' => $tuning->nginx['worker_rlimit_nofile'],
                            'open_file_cache' => $tuning->nginx['open_file_cache_max'],
                        ]] : null,
                        $tuning->mysql ? ['circle-stack', 'MySQL', [
                            'innodb_buffer_pool_size' => $tuning->mysql['innodb_buffer_pool_size_mb'].' MB',
                            'buffer_pool_instances' => $tuning->mysql['innodb_buffer_pool_instances'],
                            'innodb_redo_log_capacity' => $tuning->mysql['innodb_redo_log_capacity_mb'].' MB',
                            'max_connections' => $tuning->mysql['max_connections'],
                            'performance_schema' => $tuning->mysql['performance_schema'] ? 'ON' : 'OFF',
                        ]] : null,
                        $tuning->postgres ? ['circle-stack', 'PostgreSQL', [
                            'shared_buffers' => $tuning->postgres['shared_buffers_mb'].' MB',
                            'effective_cache_size' => $tuning->postgres['effective_cache_size_mb'].' MB',
                            'work_mem' => $tuning->postgres['work_mem_mb'].' MB',
                            'maintenance_work_mem' => $tuning->postgres['maintenance_work_mem_mb'].' MB',
                            'max_connections' => $tuning->postgres['max_connections'],
                        ]] : null,
                        ['cpu-chip', __('System'), [
                            __('Swap') => $tuning->swap['size_mb'].' MB',
                            'vm.swappiness' => $tuning->swap['swappiness'],
                            ...($tuning->redis ? [
                                'Redis maxmemory' => $tuning->redis['maxmemory_mb'].' MB',
                                'Redis policy' => $tuning->redis['maxmemory_policy'],
                            ] : []),
                        ]],
                    ]) as [$icon, $service, $values])
                        <x-panel :icon="$icon" :heading="$service">
                            <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                                @foreach ($values as $setting => $value)
                                    <div class="flex items-center justify-between gap-4 py-1.5 first:pt-0 last:pb-0">
                                        <dt class="font-mono text-xs text-zinc-500">{{ $setting }}</dt>
                                        <dd class="font-mono text-xs font-medium text-zinc-900 dark:text-zinc-100">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </x-panel>
                    @endforeach
                </div>
            @endif

            @if ($server->isActive())
                <x-panel icon="information-circle" :heading="__('Details')">
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[180px_1fr]">
                        <dt class="text-zinc-500">{{ __('Type') }}</dt>
                        <dd>{{ $server->type->label() }}</dd>
                        @if ($server->isAppServer())
                            <dt class="text-zinc-500">{{ __('PHP versions') }}</dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($server->php_versions as $version)
                                    <flux:badge size="sm" :color="$version === $server->default_php_version ? 'orange' : 'zinc'">{{ $version->value }}{{ $version === $server->default_php_version ? ' · '.__('default') : '' }}</flux:badge>
                                @endforeach
                            </dd>
                        @endif
                        @if ($server->private_ip_address)
                            <dt class="text-zinc-500">{{ __('Private IP address') }}</dt>
                            <dd class="font-mono text-xs">{{ $server->private_ip_address }}</dd>
                        @endif
                        <dt class="text-zinc-500">{{ __('SSH') }}</dt>
                        <dd class="font-mono text-xs">ssh {{ config('swage.user').'@'.$server->ip_address }} -p {{ $server->ssh_port }}</dd>
                        <dt class="self-center text-zinc-500">{{ __('SSH host key') }}</dt>
                        <dd class="flex flex-wrap items-center gap-3">
                            @if ($server->host_key)
                                <span class="font-mono text-xs break-all">{{ 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode(explode(' ', $server->host_key)[1] ?? ''), true)), '=') }}</span>
                            @else
                                <span class="text-zinc-500">{{ __('Pinned on the next connection') }}</span>
                            @endif
                        </dd>
                        <dt class="text-zinc-500">{{ __('Provisioned') }}</dt>
                        <dd>{{ $server->provisioned_at?->toDayDateTimeString() }}</dd>
                    </dl>
                </x-panel>

                <x-panel icon="circle-stack" :heading="__('Databases')" :description="__('Install the other engine or pgvector at any time. Swage then re-tunes every service to share memory with it, which restarts PHP-FPM, the databases and Redis.')">
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[180px_1fr]">
                        <dt class="text-zinc-500">{{ __('Installed') }}</dt>
                        <dd class="flex flex-wrap gap-1.5">
                            @forelse ($server->databaseEngines() as $engine)
                                <flux:badge size="sm" color="zinc">{{ $engine->labelWithVersion() }}{{ $engine === App\Enums\DatabaseEngine::Postgres && $server->has_pgvector ? ' + pgvector' : '' }}</flux:badge>
                            @empty
                                <span class="text-zinc-500">{{ __('None') }}</span>
                            @endforelse
                        </dd>
                        @if ($server->isDatabaseServer())
                            <dt class="text-zinc-500">{{ __('Connect to') }}</dt>
                            <dd class="font-mono text-xs">{{ collect($server->databaseEngines())->map(fn ($engine) => $server->connectionAddress().':'.$engine->port())->implode(', ') }}</dd>
                        @endif
                        @if ($server->hasDatabase() && auth()->user()->can('update', $server))
                            <dt class="text-zinc-500">{{ __('Admin user') }}</dt>
                            <dd class="font-mono text-xs">{{ config('swage.user') }}</dd>
                            <dt class="self-center text-zinc-500">{{ __('Admin password') }}</dt>
                            <dd><flux:input readonly copyable viewable type="password" :value="$server->database_password" size="sm" class="max-w-sm" /></dd>
                        @endif
                    </dl>

                    @if ($server->installing_database)
                        <div class="mt-4 flex items-center gap-2 text-sm text-zinc-500">
                            <flux:icon.loading variant="mini" />
                            {{ __('Installing :install…', ['install' => App\Enums\DatabaseEngine::tryFrom($server->installing_database)?->label() ?? 'pgvector']) }}
                        </div>
                    @elseif (! $server->usesMysql() || ! $server->usesPostgres() || ! $server->has_pgvector)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach (App\Enums\DatabaseEngine::cases() as $engine)
                                @unless ($server->runsDatabaseEngine($engine))
                                    <flux:button size="sm" icon="plus" wire:click="installDatabaseEngine('{{ $engine->value }}')" wire:confirm="{{ __('Install :engine on this server? Services restart once it is installed.', ['engine' => $engine->labelWithVersion()]) }}">{{ __('Install :engine', ['engine' => $engine->label()]) }}</flux:button>
                                @endunless
                            @endforeach
                            @if ($server->usesPostgres() && ! $server->has_pgvector)
                                <flux:button size="sm" icon="plus" wire:click="installPgvector" wire:confirm="{{ __('Install pgvector and enable it in every PostgreSQL database on this server?') }}">{{ __('Add pgvector') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </x-panel>
            @endif

            @can('delete', $server)
                <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-500/25 bg-red-500/[0.03] p-5">
                    <div>
                        <flux:heading>{{ __('Remove server') }}</flux:heading>
                        <flux:text class="mt-0.5">{{ __('Swage stops managing this server and removes its SSH key from it. The machine and everything on it keep running until you destroy it with your provider.') }}</flux:text>
                    </div>

                    <flux:modal.trigger name="delete-server">
                        <flux:button variant="danger" icon="trash">{{ __('Remove server') }}</flux:button>
                    </flux:modal.trigger>
                </section>
            @endcan
        </div>

        <flux:modal name="delete-server" class="md:w-96">
            {{-- Stop polling first: a refresh sent after the record is gone would fail with a 404. --}}
            <form wire:submit="delete" x-on:submit="$wire.$el.removeAttribute('wire:poll.3s')" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Remove :name?', ['name' => $server->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Type the server name to confirm.') }}</flux:text>
                </div>

                <flux:input wire:model="confirmation" :placeholder="$server->name" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Remove') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::servers.layout>
</div>
