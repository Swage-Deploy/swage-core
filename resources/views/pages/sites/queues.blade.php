<?php

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallScheduledJob;
use App\Jobs\RemoveDaemon;
use App\Jobs\RemoveScheduledJob;
use App\Models\Daemon;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteCommands;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public string $connection = '';

    public string $queue = 'default';

    public int $processes = 1;

    public int $timeout = 60;

    public int $sleep = 3;

    public int $tries = 3;


    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->connection = $this->server->has_redis ? 'redis' : 'database';
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Queues').' · '.$this->site->domain);
    }

    /**
     * @return Collection<int, Daemon>
     */
    #[Computed]
    public function daemons(): Collection
    {
        return $this->site->daemons()->latest()->get();
    }

    #[Computed]
    public function scheduler(): ?ScheduledJob
    {
        return $this->site->scheduledJobs()->where('command', SiteCommands::scheduler($this->site))->first();
    }

    public function createWorker(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'connection' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'queue' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_,:.-]+$/D'],
            'processes' => ['required', 'integer', 'between:1,64'],
            'timeout' => ['required', 'integer', 'between:0,86400'],
            'sleep' => ['required', 'integer', 'between:0,3600'],
            'tries' => ['required', 'integer', 'between:0,100'],
        ]);

        $daemon = $this->server->daemons()->create([
            'site_id' => $this->site->id,
            'command' => SiteCommands::queueWorker($this->site, $validated),
            'directory' => $this->site->currentPath(),
            'user' => $this->site->systemUser(),
            'processes' => $validated['processes'],
            'stop_wait_seconds' => $validated['timeout'] + 10,
            'status' => ResourceStatus::Pending,
        ]);

        InstallDaemon::dispatch($daemon);

        Flux::modal('create-worker')->close();
        Flux::toast(variant: 'success', text: __('Starting queue worker.'));
    }

    public function restart(int $daemonId): void
    {
        $this->authorize('update', $this->site);

        InstallDaemon::dispatch($this->site->daemons()->findOrFail($daemonId));
    }

    public function remove(int $daemonId): void
    {
        $this->authorize('update', $this->site);

        $daemon = $this->site->daemons()->findOrFail($daemonId);
        $daemon->update(['status' => ResourceStatus::Removing]);

        RemoveDaemon::dispatch($daemon);
    }

    public function toggleScheduler(): void
    {
        $this->authorize('update', $this->site);

        if ($scheduler = $this->scheduler) {
            $scheduler->update(['status' => ResourceStatus::Removing]);
            RemoveScheduledJob::dispatch($scheduler);

            return;
        }

        InstallScheduledJob::dispatch($this->server->scheduledJobs()->create([
            'site_id' => $this->site->id,
            'command' => SiteCommands::scheduler($this->site),
            'user' => $this->site->systemUser(),
            'frequency' => '* * * * *',
            'status' => ResourceStatus::Pending,
        ]));

        unset($this->scheduler);
    }
}; ?>

<div @if ($this->daemons->contains(fn ($daemon) => $daemon->status->isBusy()) || $this->scheduler?->status->isBusy()) wire:poll.3s @endif>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="clock" :heading="__('Laravel scheduler')" :description="__('Runs :command every minute.', ['command' => 'php artisan schedule:run'])">
                <x-slot:actions>
                    @if ($this->scheduler)
                        <x-status :color="$this->scheduler->status->color()" :label="$this->scheduler->status === ResourceStatus::Installed ? __('Enabled') : $this->scheduler->status->label()" class="me-2" />
                        <flux:button size="sm" icon="document-text" :href="route('servers.sites.logs', [$server, $site, 'log' => 'scheduled-job-'.$this->scheduler->id])" wire:navigate>{{ __('View log') }}</flux:button>
                        <flux:button size="sm" wire:click="toggleScheduler" :disabled="$this->scheduler->status->isBusy()">{{ __('Disable') }}</flux:button>
                    @else
                        <x-status :label="__('Disabled')" class="me-2" />
                        <flux:button size="sm" variant="primary" wire:click="toggleScheduler">{{ __('Enable scheduler') }}</flux:button>
                    @endif
                </x-slot:actions>
            </x-panel>

            <x-panel icon="queue-list" :heading="__('Queue workers')" :description="__('Restarted automatically by $RESTART_QUEUES() in the deploy script.')" flush>
                <x-slot:actions>
                    <flux:modal.trigger name="create-worker">
                        <flux:button size="sm" variant="primary" icon="plus">{{ __('New worker') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>

                @include('partials.daemon-table', ['daemons' => $this->daemons])
            </x-panel>
        </div>

        <flux:modal name="create-worker" class="w-full max-w-lg">
            <form wire:submit="createWorker" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('New queue worker') }}</flux:heading>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="connection" :label="__('Connection')" required />
                    <flux:input wire:model="queue" :label="__('Queue')" :description="__('Comma separated, highest priority first.')" required />
                    <flux:input wire:model="processes" type="number" min="1" :label="__('Processes')" />
                    <flux:input wire:model="timeout" type="number" min="0" :label="__('Timeout (s)')" />
                    <flux:input wire:model="sleep" type="number" min="0" :label="__('Sleep (s)')" />
                    <flux:input wire:model="tries" type="number" min="0" :label="__('Tries')" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Start worker') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::sites.layout>
</div>
