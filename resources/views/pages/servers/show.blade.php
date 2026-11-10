<?php

use App\Actions\Servers\ChangePhpVersions;
use App\Actions\Servers\RemoveServer;
use App\Enums\DatabaseEngine;
use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\InstallPgvector;
use App\Jobs\OptimizeServer;
use App\Jobs\RebootServer;
use App\Jobs\RecordServerMetrics;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Provisioning\ServerTuning;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
     * The server's readings from the last day, oldest first, for the system monitor's trends.
     *
     * @return Collection<int, ServerMetric>
     */
    #[Computed]
    public function metrics(): Collection
    {
        return $this->server->metrics()->where('created_at', '>=', now()->subDay())->orderBy('created_at')->get();
    }

    #[Computed]
    public function latestMetric(): ?ServerMetric
    {
        return $this->metrics->last() ?? $this->server->latestMetric;
    }

    /**
     * Take a reading now rather than waiting for the next one, at most every 15 seconds per server.
     */
    public function refreshMetrics(): void
    {
        abort_unless($this->server->isActive(), 409);

        if (! RateLimiter::attempt("server-metrics:{$this->server->id}", 1, fn () => null, 15)) {
            return;
        }

        $metric = app()->call([new RecordServerMetrics($this->server), 'handle']);

        unset($this->metrics, $this->latestMetric);

        if (! $metric) {
            Flux::toast(variant: 'danger', text: __('Couldn\'t read the server\'s usage. Check that it\'s running and Swage can connect over SSH.'));
        }
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
     * How many sites run each installed PHP version, and what else on the server still runs it.
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function phpVersionUsers(): array
    {
        return $this->server->php_versions->mapWithKeys(fn (PhpVersion $php): array => [$php->value => $this->server->phpVersionUsers($php)])->all();
    }

    /**
     * Install a PHP version beside the server's others, for its sites to switch to.
     */
    public function installPhpVersion(ChangePhpVersions $changePhpVersions, string $version): void
    {
        $this->authorize('update', $this->server);

        $this->startPhpChange($changePhpVersions->install($this->server, $version), __('Installing PHP :version. Follow along in the activity log.', ['version' => $version]));
    }

    /**
     * Make an installed PHP version the one `php` runs on the command line.
     */
    public function makePhpVersionDefault(ChangePhpVersions $changePhpVersions, string $version): void
    {
        $this->authorize('update', $this->server);

        $this->startPhpChange($changePhpVersions->makeDefault($this->server, $version), __('Making PHP :version the default. Follow along in the activity log.', ['version' => $version]));
    }

    /**
     * Remove a PHP version nothing on the server runs any more.
     */
    public function removePhpVersion(ChangePhpVersions $changePhpVersions, string $version): void
    {
        $this->authorize('update', $this->server);

        try {
            $started = $changePhpVersions->remove($this->server, $version);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        }

        $this->startPhpChange($started, __('Removing PHP :version. Follow along in the activity log.', ['version' => $version]));
    }

    protected function startPhpChange(bool $started, string $message): void
    {
        $this->server->refresh();

        Flux::toast(...$started
            ? ['variant' => 'success', 'text' => $message]
            : ['variant' => 'warning', 'text' => __('Another install is already running on this server. Try again once it finishes.')]);
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
        $claimed = Server::query()->whereKey($this->server->id)->whereNull('installing_database')->whereNull('php_task')->update(['installing_database' => $install]) === 1;

        $this->server->refresh();

        if (! $claimed) {
            Flux::toast(variant: 'warning', text: __('Another install is already running on this server. Try again once it finishes.'));
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

<div @if (in_array($server->status, [ServerStatus::Pending, ServerStatus::Provisioning]) || $server->installing_database || $server->php_task) wire:poll.3s @endif>
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

            @if ($server->isActive())
                <x-panel icon="chart-bar" :heading="__('System monitor')" :description="__('Swage reads the server\'s usage every five minutes. Trends cover the last 24 hours.')" wire:poll.60s>
                    <x-slot:actions>
                        <flux:button size="sm" icon="arrow-path" wire:click="refreshMetrics">{{ __('Refresh') }}</flux:button>
                    </x-slot:actions>

                    @if ($metric = $this->latestMetric)
                        @php($history = fn (Closure $value, Closure $label): array => $this->metrics->map(fn (ServerMetric $reading): array => ['at' => $reading->created_at, 'value' => $value($reading), 'label' => $label($reading)])->all())
                        @php($cores = max(1, $server->cpu_cores ?? 1))

                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <x-metric-tile
                                :label="__('CPU')"
                                :value="Number::format($metric->cpu_percent, precision: 1).'%'"
                                :detail="trans_choice(':count core|:count cores', $cores)"
                                :percent="$metric->cpu_percent"
                                :history="$history(fn ($reading) => $reading->cpu_percent, fn ($reading) => Number::format($reading->cpu_percent, precision: 1).'%')"
                            />
                            <x-metric-tile
                                :label="$metric->load_is_host ? __('Host load') : __('Load')"
                                :value="Number::format($metric->load_1, precision: 2)"
                                :detail="__('5 min :five · 15 min :fifteen', ['five' => Number::format($metric->load_5, precision: 2), 'fifteen' => Number::format($metric->load_15, precision: 2)])"
                                :percent="$metric->load_1 / $cores * 100"
                                :meter="! $metric->load_is_host"
                                :history="$history(fn ($reading) => $reading->load_1, fn ($reading) => Number::format($reading->load_1, precision: 2))"
                                :max="max($cores, $this->metrics->max('load_1') ?? 0)"
                            />
                            <x-metric-tile
                                :label="__('Memory')"
                                :value="Number::format($metric->memoryPercent(), precision: 0).'%'"
                                :detail="__(':used of :total', ['used' => Number::fileSize($metric->memory_used, precision: 1), 'total' => Number::fileSize($metric->memory_total, precision: 1)]).($metric->swap_total ? ' · '.__('swap :used', ['used' => Number::fileSize($metric->swap_used, precision: 1)]) : '')"
                                :percent="$metric->memoryPercent()"
                                :history="$history(fn ($reading) => $reading->memoryPercent(), fn ($reading) => Number::fileSize($reading->memory_used, precision: 1))"
                            />
                            <x-metric-tile
                                :label="__('Disk')"
                                :value="Number::format($metric->diskPercent(), precision: 0).'%'"
                                :detail="__(':free free of :total', ['free' => Number::fileSize($metric->disk_total - $metric->disk_used, precision: 1), 'total' => Number::fileSize($metric->disk_total, precision: 1)])"
                                :percent="$metric->diskPercent()"
                                :high="__('Filling up')"
                                :history="$history(fn ($reading) => $reading->diskPercent(), fn ($reading) => Number::fileSize($reading->disk_used, precision: 1))"
                            />
                        </div>

                        <flux:text class="mt-4 text-xs">
                            @if ($metric->load_is_host)
                                {{ __('This server is a container, so its load is its host\'s, shared with everything else running there.') }}
                            @endif
                            {{ __('Up :uptime. Read :time.', ['uptime' => Carbon\CarbonInterval::seconds($metric->uptime_seconds)->cascade()->forHumans(['parts' => 2]), 'time' => $metric->created_at->diffForHumans()]) }}
                            @if ($metric->created_at->lt(now()->subMinutes(15)))
                                <span class="text-amber-600 dark:text-amber-400">{{ __('Swage hasn\'t been able to read the server since.') }}</span>
                            @endif
                        </flux:text>
                    @else
                        <x-empty-state icon="chart-bar" :heading="__('No readings yet')" class="py-8">
                            {{ __('The first reading arrives within five minutes, or refresh to take one now.') }}
                        </x-empty-state>
                    @endif
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

                @if ($server->isAppServer())
                    @php($canUpdate = auth()->user()->can('update', $server))
                    <x-panel icon="code-bracket" :heading="__('PHP')" :description="__('Install another PHP version at any time, then switch sites to it in their settings. Installing, removing or changing the default re-tunes every service, which restarts PHP-FPM, the databases and Redis.')">
                        <div class="flex flex-col divide-y divide-zinc-100 text-sm dark:divide-white/5">
                            @foreach ($server->php_versions as $version)
                                @php($users = $this->phpVersionUsers[$version->value] ?? [])
                                <div class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono font-medium">PHP {{ $version->value }}</span>
                                        @if ($version === $server->default_php_version)
                                            <flux:badge size="sm" color="orange">{{ __('default') }}</flux:badge>
                                        @endif
                                        <flux:text class="text-xs">{{ trans_choice('{0} Nothing uses it|{1} Used by :count site, worker or job|[2,*] Used by :count sites, workers or jobs', count($users)) }}</flux:text>
                                    </div>
                                    @if ($canUpdate && $version !== $server->default_php_version && ! $server->php_task)
                                        <div class="flex gap-2">
                                            <flux:button size="xs" wire:click="makePhpVersionDefault('{{ $version->value }}')" wire:confirm="{{ __('Make PHP :version the default? \'php\' on the command line and Composer run it, and its PHP-FPM pool gets the largest share of memory. Services restart.', ['version' => $version->value]) }}">{{ __('Make default') }}</flux:button>
                                            @if ($users)
                                                <flux:tooltip :content="__('Move :users to another version first.', ['users' => implode(', ', $users)])">
                                                    <div><flux:button size="xs" variant="ghost" icon="trash" disabled>{{ __('Remove') }}</flux:button></div>
                                                </flux:tooltip>
                                            @else
                                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removePhpVersion('{{ $version->value }}')" wire:confirm="{{ __('Remove PHP :version from this server? Its packages and settings are deleted. Services restart.', ['version' => $version->value]) }}">{{ __('Remove') }}</flux:button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($server->php_task)
                            <div class="mt-4 flex items-center gap-2 text-sm text-zinc-500">
                                <flux:icon.loading variant="mini" />
                                {{ $server->php_task->label($server->php_task_version) }}…
                            </div>
                        @elseif ($server->missingPhpVersions() && $canUpdate)
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($server->missingPhpVersions() as $version)
                                    <flux:button size="sm" icon="plus" wire:click="installPhpVersion('{{ $version->value }}')" wire:confirm="{{ __('Install PHP :version on this server? Services restart once it is installed.', ['version' => $version->value]) }}">{{ __('Install PHP :version', ['version' => $version->value]) }}</flux:button>
                                @endforeach
                            </div>
                        @endif
                    </x-panel>
                @endif

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
