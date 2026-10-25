<?php

use App\Enums\DeploymentStatus;
use App\Jobs\RunSiteCommand;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Sites\ArtisanCommand;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    /**
     * What follows "php artisan".
     */
    public string $command = '';

    #[Locked]
    public ?int $viewingCommandId = null;

    /**
     * Commands offered as a starting point.
     *
     * @var list<string>
     */
    public const array SUGGESTIONS = ['migrate --force', 'optimize:clear', 'cache:clear', 'queue:restart', 'about'];

    public function mount(): void
    {
        $this->authorize('update', $this->site);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Commands').' · '.$this->site->domain);
    }

    /**
     * @return Collection<int, SiteCommand>
     */
    #[Computed]
    public function commands(): Collection
    {
        return $this->site->commands()->with('user:id,name')->latest('id')->limit(25)
            ->get(['id', 'site_id', 'user_id', 'command', 'status', 'exit_code', 'started_at', 'finished_at', 'created_at']);
    }

    #[Computed]
    public function viewingCommand(): ?SiteCommand
    {
        return $this->viewingCommandId ? $this->site->commands()->find($this->viewingCommandId) : null;
    }

    /**
     * Run the command in the site's live release, in the background like a deployment.
     */
    public function run(): void
    {
        $this->authorize('update', $this->site);

        $this->validate(['command' => ['required', 'string', 'max:1000']]);

        try {
            $artisan = ArtisanCommand::parse($this->command);
        } catch (InvalidArgumentException $exception) {
            $this->addError('command', $exception->getMessage());

            return;
        }

        $command = $this->site->commands()->create([
            'user_id' => Auth::id(),
            'command' => implode(' ', array_map(fn (string $argument): string => preg_match('/^[A-Za-z0-9:_.,=\/@%+-]+$/D', $argument) ? $argument : escapeshellarg($argument), $artisan->arguments)),
            'status' => DeploymentStatus::Pending,
        ]);

        RunSiteCommand::dispatch($command);

        $this->command = '';
        unset($this->commands);

        $this->viewCommand($command->id);
    }

    public function viewCommand(int $commandId): void
    {
        $this->viewingCommandId = $this->site->commands()->findOrFail($commandId)->id;

        Flux::modal('command-output')->show();
    }
}; ?>

<div @if ($this->commands->contains(fn ($command) => $command->status->isInProgress())) wire:poll.2s @endif>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="command-line" :heading="__('Run an Artisan command')" :description="__('Runs in the site\'s live release as :user, with PHP :php. Commands that keep running, such as queue:work, belong in a daemon instead.', ['user' => $site->systemUser(), 'php' => $site->php_version->value])">
                <form wire:submit="run" class="flex flex-col gap-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div class="flex-1">
                            <flux:input.group>
                                <flux:input.group.prefix class="font-mono">php artisan</flux:input.group.prefix>
                                <flux:input wire:model="command" class="font-mono" placeholder="migrate --force" autocomplete="off" spellcheck="false" :aria-label="__('Command')" />
                            </flux:input.group>
                            <flux:error name="command" />
                        </div>
                        <flux:button type="submit" variant="primary" icon="play" :disabled="! $site->hasRepository()">{{ __('Run') }}</flux:button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($this::SUGGESTIONS as $suggestion)
                            <button type="button" wire:click="$set('command', '{{ $suggestion }}')" class="rounded-md bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-600 transition hover:bg-zinc-200 hover:text-zinc-900 dark:bg-white/5 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white">{{ $suggestion }}</button>
                        @endforeach
                    </div>
                </form>
            </x-panel>

            <x-panel icon="clock" :heading="__('History')" flush>
                @if ($this->commands->isEmpty())
                    <x-empty-state icon="command-line" :heading="__('No commands run yet')">
                        {{ __('Commands you run here, and their output, are kept for :days days.', ['days' => App\Models\SiteCommand::RETENTION_DAYS]) }}
                    </x-empty-state>
                @else
                    <ol class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($this->commands as $command)
                            <li wire:key="command-{{ $command->id }}" class="flex items-center gap-4 px-5 py-3">
                                <x-status :color="$command->status->color()" class="w-20" :label="$command->status->label()" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-mono text-sm text-zinc-900 dark:text-zinc-100">php artisan {{ $command->command }}</div>
                                    <div class="mt-0.5 text-xs text-zinc-500">
                                        {{ $command->user?->name ?? __('A removed user') }} · {{ $command->created_at->diffForHumans() }}
                                        @if ($command->exit_code !== null && $command->exit_code !== 0)
                                            · {{ __('exit code :code', ['code' => $command->exit_code]) }}
                                        @endif
                                    </div>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="command-line" wire:click="viewCommand({{ $command->id }})">{{ __('Output') }}</flux:button>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-panel>
        </div>

        <flux:modal name="command-output" class="w-full max-w-4xl">
            @if ($viewed = $this->viewingCommand)
                <div class="flex flex-col gap-4" @if ($viewed->status->isInProgress()) wire:poll.2s @endif>
                    <div class="flex items-center gap-3">
                        <flux:heading size="lg" class="font-mono">php artisan {{ $viewed->command }}</flux:heading>
                        <x-status :color="$viewed->status->color()" :label="$viewed->status->label()" />
                    </div>
                    <x-terminal :title="$site->domain">{{ $viewed->output ?: ($viewed->status === DeploymentStatus::Pending ? __('Waiting to start...') : __('No output.')) }}</x-terminal>
                </div>
            @endif
        </flux:modal>
    </x-pages::sites.layout>
</div>
