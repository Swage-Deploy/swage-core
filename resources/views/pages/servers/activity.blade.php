<?php

use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public ?int $viewingTaskId = null;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Activity').' · '.$this->server->name);
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->server->tasks()->latest()->limit(50)->get(['id', 'server_id', 'name', 'status', 'exit_code', 'created_at', 'updated_at']);
    }

    #[Computed]
    public function viewingTask(): ?Task
    {
        return $this->viewingTaskId ? $this->server->tasks()->find($this->viewingTaskId) : null;
    }
}; ?>

<div @if ($this->tasks->contains(fn ($task) => $task->status === TaskStatus::Running)) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        <x-panel icon="queue-list" :heading="__('Activity')" :description="__('Every script Swage has run on this server.')" flush>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column>{{ __('Started') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->tasks as $task)
                    <flux:table.row :key="$task->id">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">{{ $task->name }}</flux:table.cell>
                        <flux:table.cell class="text-xs">{{ $task->created_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell><x-status :color="$task->status->color()" :label="ucfirst($task->status->value)" /></flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="command-line" wire:click="$set('viewingTaskId', {{ $task->id }})" x-on:click="$flux.modal('task-output').show()">{{ __('Output') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('Nothing has run on this server yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </x-panel>

        <flux:modal name="task-output" class="w-full max-w-4xl">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ $this->viewingTask?->name }}</flux:heading>
                <x-terminal :title="$server->name">{{ $this->viewingTask?->output ?: __('No output.') }}</x-terminal>
            </div>
        </flux:modal>
    </x-pages::servers.layout>
</div>
