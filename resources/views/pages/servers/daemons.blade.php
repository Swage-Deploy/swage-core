<?php

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Daemon;
use App\Models\Server;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public string $command = '';

    public string $directory = '';

    public string $user = '';

    public int $processes = 1;

    public int $stop_wait_seconds = 10;

    public function mount(): void
    {
        $this->authorize('view', $this->server);

        $this->user = config('swage.user');
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Daemons').' · '.$this->server->name);
    }

    /**
     * @return Collection<int, Daemon>
     */
    #[Computed]
    public function daemons(): Collection
    {
        return $this->server->daemons()->with('site')->latest()->get();
    }

    public function create(): void
    {
        $this->authorize('update', $this->server);

        $validated = $this->validate([
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'directory' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]*$/D'],
            'user' => ['required', Rule::in($this->server->systemUsers())],
            'processes' => ['required', 'integer', 'between:1,64'],
            'stop_wait_seconds' => ['required', 'integer', 'between:1,3600'],
        ]);

        InstallDaemon::dispatch($this->server->daemons()->create([...$validated, 'status' => ResourceStatus::Pending]));

        $this->reset('command', 'directory', 'processes', 'stop_wait_seconds');
        Flux::modal('create-daemon')->close();
        Flux::toast(variant: 'success', text: __('Installing daemon.'));
    }

    public function restart(int $daemonId): void
    {
        $this->authorize('update', $this->server);

        InstallDaemon::dispatch($this->server->daemons()->findOrFail($daemonId));
    }

    public function remove(int $daemonId): void
    {
        $this->authorize('update', $this->server);

        $daemon = $this->server->daemons()->findOrFail($daemonId);
        $daemon->update(['status' => ResourceStatus::Removing]);

        RemoveDaemon::dispatch($daemon);
    }
}; ?>

<div @if ($this->daemons->contains(fn ($daemon) => $daemon->status->isBusy())) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        <x-panel icon="cog-6-tooth" :heading="__('Daemons')" :description="__('Long-running processes kept alive by Supervisor. Queue workers for a site live on the site\'s Queues page.')" flush>
            <x-slot:actions>
                <flux:modal.trigger name="create-daemon">
                    <flux:button size="sm" variant="primary" icon="plus">{{ __('New daemon') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>

            @include('partials.daemon-table', ['daemons' => $this->daemons])
        </x-panel>

        <flux:modal name="create-daemon" class="w-full max-w-lg">
            <form wire:submit="create" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('New daemon') }}</flux:heading>

                <flux:input wire:model="command" :label="__('Command')" placeholder="php8.5 /home/swage/example.com/current/artisan reverb:start" class="font-mono" required />
                <flux:input wire:model="directory" :label="__('Directory')" placeholder="/home/swage/example.com/current" />

                <div class="grid grid-cols-3 gap-4">
                    <flux:select wire:model="user" :label="__('User')">
                        @foreach ($server->systemUsers() as $systemUser)
                            <flux:select.option :value="$systemUser">{{ $systemUser }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="processes" type="number" min="1" :label="__('Processes')" />
                    <flux:input wire:model="stop_wait_seconds" type="number" min="1" :label="__('Stop wait (s)')" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Create daemon') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::servers.layout>
</div>
