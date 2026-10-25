<?php

use App\Enums\DeploymentStatus;
use App\Models\SelfUpdate;
use App\SelfUpdate\SelfUpdater;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Updates')] class extends Component {
    public ?string $currentCommit = null;

    public ?string $currentVersion = null;

    /**
     * What an update would install, once checked.
     *
     * @var array{ref: string, commit: string, version: ?string}|null
     */
    public ?array $latest = null;

    public bool $checked = false;

    public ?int $viewingUpdateId = null;

    public function mount(SelfUpdater $updater): void
    {
        $this->authorize('administer');

        $this->currentCommit = $updater->currentCommit();
        $this->currentVersion = $updater->currentVersion();
    }

    /**
     * @return Collection<int, SelfUpdate>
     */
    #[Computed]
    public function updates(): Collection
    {
        return SelfUpdate::with('user')->latest('id')->limit(20)
            ->get(['id', 'user_id', 'status', 'from_version', 'to_version', 'plugin_operation', 'started_at', 'finished_at', 'created_at']);
    }

    #[Computed]
    public function viewingUpdate(): ?SelfUpdate
    {
        return $this->viewingUpdateId ? SelfUpdate::find($this->viewingUpdateId) : null;
    }

    public function check(SelfUpdater $updater): void
    {
        $this->authorize('administer');

        $this->currentCommit = $updater->currentCommit();
        $this->currentVersion = $updater->runningVersion();
        $this->latest = $updater->latest();
        $this->checked = true;

        if ($this->latest === null) {
            Flux::toast(variant: 'danger', text: __('Unable to reach the repository.'));
        }
    }

    public function startUpdate(SelfUpdater $updater): void
    {
        $this->authorize('administer');

        if (! $updater->isSupported()) {
            Flux::toast(variant: 'danger', text: __('Self-update only works on installs created by the Swage installer.'));

            return;
        }

        if ($updater->isUpdating()) {
            Flux::toast(variant: 'warning', text: __('An update is already running.'));

            return;
        }

        $this->viewDetails($updater->launch(Auth::user())->id);
    }

    /**
     * Reboot the panel host to finish installing updates, through the one root command Swage may run for it.
     */
    public function reboot(SelfUpdater $updater): void
    {
        $this->authorize('administer');

        if (! is_file(config('swage.self_update.reboot_required_file'))) {
            Flux::toast(variant: 'warning', text: __('The panel host does not need a reboot.'));

            return;
        }

        if ($updater->isUpdating()) {
            Flux::toast(variant: 'warning', text: __('An update is running. Reboot once it finishes.'));

            return;
        }

        $result = Process::timeout(30)->run(['sudo', '-n', config('swage.self_update.rebooter')]);

        if ($result->failed()) {
            Flux::toast(variant: 'danger', text: __('Swage could not reboot the host. Update Swage first, so the host lets it: :output', ['output' => trim($result->errorOutput() ?: $result->output())]));

            return;
        }

        Flux::toast(variant: 'success', duration: 10000, text: __('The panel host reboots in a minute. Swage is unavailable until it is back.'));
    }

    public function viewDetails(int $updateId): void
    {
        $this->viewingUpdateId = $updateId;

        Flux::modal('update-output')->show();
    }
}; ?>

<div class="flex flex-col gap-6" @if ($this->updates->contains(fn ($update) => $update->status->isInProgress())) wire:poll.2s @endif>
    @php($updater = app(App\SelfUpdate\SelfUpdater::class))

    <div>
        <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Updates') }}</flux:heading>
        <flux:subheading>{{ __('Update Swage to its newest version tag. A new release is built beside the running one and only goes live once it passes a health check.') }}</flux:subheading>
    </div>

    @unless ($updater->isSupported())
        <flux:callout icon="information-circle" color="amber">
            <flux:callout.text>{{ __('This copy of Swage was not installed by the Swage installer, so it cannot update itself. Update it with git instead.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    @if (is_file(config('swage.self_update.reboot_required_file')))
        <flux:callout icon="arrow-path" color="amber">
            <flux:callout.heading>{{ __('The panel host needs a reboot') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Automatic updates installed changes that only take effect after a reboot.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" icon="arrow-path" wire:click="reboot"
                    wire:confirm="{{ __('Reboot the panel host? Swage is unavailable for a minute or two, and running deployments and workers on it are interrupted.') }}">{{ __('Reboot now') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <section class="relative isolate flex flex-wrap items-center justify-between gap-6 overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-white/8 dark:bg-zinc-900/70">
        <div class="provision-grid absolute inset-0 -z-10 text-zinc-900 dark:text-white"></div>
        <div class="flex flex-wrap items-center gap-6">
            <div>
                <div class="text-xs font-medium tracking-wider text-zinc-500 uppercase">{{ __('Running') }}</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="font-mono text-xl text-zinc-900 dark:text-white">{{ $currentVersion ?? __('unknown') }}</span>
                </div>
            </div>
            @if ($checked)
                <flux:icon.arrow-long-right class="text-zinc-400" />
                <div>
                    <div class="text-xs font-medium tracking-wider text-zinc-500 uppercase">{{ $latest && $latest['version'] === null ? __('Latest on :branch', ['branch' => $latest['ref']]) : __('Latest version') }}</div>
                    <div class="mt-1 flex items-center gap-3">
                        <span class="font-mono text-xl text-zinc-900 dark:text-white">{{ $latest ? ($latest['version'] ?? $latest['ref']) : __('unknown') }}</span>
                        @if ($latest && ! $updater->isNewer($latest, $currentCommit, $currentVersion))
                            <x-status color="green" :label="__('Up to date')" />
                        @elseif ($latest)
                            <x-status color="amber" :label="__('Update available')" />
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="flex gap-2">
            <flux:button icon="arrow-path" wire:click="check">{{ __('Check for updates') }}</flux:button>
            <flux:button variant="primary" icon="arrow-down-tray" wire:click="startUpdate" wire:confirm="{{ __('Update Swage now?') }}" :disabled="! $updater->isSupported() || $this->updates->contains(fn ($update) => $update->status->isInProgress())">{{ __('Update now') }}</flux:button>
        </div>
    </section>

    <x-panel icon="clock" :heading="__('History')" flush>
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Change') }}</flux:table.column>
            <flux:table.column>{{ __('Started by') }}</flux:table.column>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->updates as $update)
                <flux:table.row :key="$update->id">
                    <flux:table.cell>
                        @if ($update->plugin_operation)
                            <span class="text-sm">{{ $update->title() }}</span>
                        @else
                            <span class="font-mono text-xs">{{ $update->from_version ?? __('unknown') }} → {{ $update->to_version ?? __('unknown') }}</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $update->user?->name ?? __('CLI') }}</flux:table.cell>
                    <flux:table.cell>{{ $update->created_at->diffForHumans() }}</flux:table.cell>
                    <flux:table.cell><x-status :color="$update->status->color()" :label="$update->status->label()" /></flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="command-line" wire:click="viewDetails({{ $update->id }})">{{ __('Output') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('No updates yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </x-panel>

    <flux:callout icon="puzzle-piece" color="zinc">
        <flux:callout.text>{{ __('Plugins are installed and updated from the Plugins page. Each change rebuilds Swage the same way an update does, and shows up in the history above.') }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" :href="route('plugins')" wire:navigate>{{ __('Manage plugins') }}</flux:button>
        </x-slot>
    </flux:callout>

    <flux:modal name="update-output" class="w-full max-w-4xl">
        @if ($selected = $this->viewingUpdate)
            <div class="flex flex-col gap-4" @if ($selected->status->isInProgress()) wire:poll.2s @endif>
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">{{ __('Update #:id', ['id' => $selected->id]) }}</flux:heading>
                    <x-status :color="$selected->status->color()" :label="$selected->status->label()" />
                </div>
                <x-terminal title="swage:update">{{ $selected->output ?: __('Waiting for output...') }}</x-terminal>
            </div>
        @endif
    </flux:modal>
</div>
