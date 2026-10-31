<?php

use App\Actions\Sites\CreateQueueWorker;
use App\Actions\Sites\SetReverb;
use App\Actions\Sites\SetScheduler;
use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Daemon;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Models\Site;
use App\Ssh\RemoteShell;
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

    /**
     * Whether the site's live release has laravel/reverb, once checked. Null until then, or when the server cannot say.
     */
    public ?bool $reverbInstalled = null;


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
        return $this->site->daemons()->latest()->get()->reject->isReverb();
    }

    #[Computed]
    public function reverbDaemon(): ?Daemon
    {
        return $this->site->daemons()->get()->first->isReverb();
    }

    #[Computed]
    public function scheduler(): ?ScheduledJob
    {
        return SetScheduler::current($this->site);
    }

    public function createWorker(CreateQueueWorker $createQueueWorker): void
    {
        $this->authorize('update', $this->site);

        $createQueueWorker->handle($this->site, $this->only(['connection', 'queue', 'processes', 'timeout', 'sleep', 'tries']));

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

    /**
     * Check the site's live release for laravel/reverb, after the page has loaded.
     */
    public function checkReverb(RemoteShell $shell): void
    {
        $this->reverbInstalled = SetReverb::installed($this->site, $shell);
    }

    public function toggleReverb(SetReverb $setReverb): void
    {
        $this->authorize('update', $this->site);

        $setReverb->handle($this->site, ! $this->site->reverb);

        unset($this->reverbDaemon);
        Flux::toast(variant: 'success', text: $this->site->reverb
            ? __('Starting Reverb. Deploy the site afterwards so its front end is built with the new settings.')
            : __('Stopping Reverb.'));
    }

    public function toggleScheduler(SetScheduler $setScheduler): void
    {
        $this->authorize('update', $this->site);

        $setScheduler->handle($this->site, $this->scheduler === null);

        unset($this->scheduler);
    }
}; ?>

<div wire:init="checkReverb" @if ($this->daemons->contains(fn ($daemon) => $daemon->status->isBusy()) || $this->scheduler?->status->isBusy() || $this->reverbDaemon?->status->isBusy() || ($site->reverb && $site->status->isBusy())) wire:poll.3s @endif>
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

            @if ($site->reverb || $this->reverbInstalled)
                <x-panel icon="signal" :heading="__('Laravel Reverb')" :description="$site->reverb_port
                    ? __('Runs :command on port :port, with WebSocket traffic to /app and /apps sent to it.', ['command' => 'php artisan reverb:start', 'port' => $site->reverb_port])
                    : __('The site uses laravel/reverb. Enabling it runs :command on a port of its own, with WebSocket traffic to /app and /apps sent to it.', ['command' => 'php artisan reverb:start'])">
                    <x-slot:actions>
                        @if ($site->reverb)
                            @php($status = $site->status->isBusy() ? $site->status : ($this->reverbDaemon?->status ?? $site->status))
                            <x-status :color="$status->color()" :label="$status === ResourceStatus::Installed ? __('Enabled') : $status->label()" class="me-2" />
                            @if ($this->reverbDaemon)
                                <flux:button size="sm" icon="document-text" :href="route('servers.sites.logs', [$server, $site, 'log' => 'daemon-'.$this->reverbDaemon->id])" wire:navigate>{{ __('View log') }}</flux:button>
                            @endif
                            <flux:button size="sm" wire:click="toggleReverb" :disabled="$status->isBusy()">{{ __('Disable') }}</flux:button>
                        @else
                            <x-status :label="__('Disabled')" class="me-2" />
                            <flux:button size="sm" variant="primary" wire:click="toggleReverb" :disabled="$site->status->isBusy()">{{ __('Enable Reverb') }}</flux:button>
                        @endif
                    </x-slot:actions>
                </x-panel>
            @endif

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
