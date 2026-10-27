<?php

use App\Actions\Servers\CreateScheduledJob;
use App\Enums\ResourceStatus;
use App\Jobs\RemoveScheduledJob;
use App\Models\ScheduledJob;
use App\Models\Server;
use Cron\CronExpression;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Common schedules offered in the frequency picker.
     *
     * @var array<string, string>
     */
    public const array FREQUENCIES = [
        '* * * * *' => 'Every minute',
        '0 * * * *' => 'Hourly',
        '0 0 * * *' => 'Nightly',
        '0 0 * * 0' => 'Weekly',
        '0 0 1 * *' => 'Monthly',
        'custom' => 'Custom',
    ];

    public Server $server;

    public string $command = '';

    public string $user = '';

    public string $frequency = '* * * * *';

    public string $custom_frequency = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);

        $this->user = config('swage.user');
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Scheduler').' · '.$this->server->name);
    }

    /**
     * @return Collection<int, ScheduledJob>
     */
    #[Computed]
    public function jobs(): Collection
    {
        return $this->server->scheduledJobs()->with('site')->latest()->get();
    }

    public function create(CreateScheduledJob $createScheduledJob): void
    {
        $this->authorize('update', $this->server);

        if ($this->user === 'root') {
            $this->authorize('runAsRoot', $this->server);
        }

        $this->validate([
            'frequency' => ['required', Rule::in(array_keys(self::FREQUENCIES))],
            'custom_frequency' => ['required_if:frequency,custom', 'nullable', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->frequency === 'custom' && ! CronExpression::isValidExpression((string) $value)
                    ? $fail(__('Enter a valid cron expression.'))
                    : null,
            ],
        ]);

        $createScheduledJob->handle($this->server, [
            'command' => $this->command,
            'user' => $this->user,
            'frequency' => $this->frequency === 'custom' ? $this->custom_frequency : $this->frequency,
        ]);

        $this->reset('command', 'custom_frequency');
        Flux::toast(variant: 'success', text: __('Scheduling job.'));
    }

    public function remove(int $jobId): void
    {
        $this->authorize('update', $this->server);

        $job = $this->server->scheduledJobs()->findOrFail($jobId);
        $job->update(['status' => ResourceStatus::Removing]);

        RemoveScheduledJob::dispatch($job);
    }
}; ?>

<div @if ($this->jobs->contains(fn ($job) => $job->status->isBusy())) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        <div class="flex flex-col gap-6">
            <x-panel icon="clock" :heading="__('New scheduled job')" :description="__('Cron jobs on this server.')">
            <form wire:submit="create" class="flex max-w-2xl flex-col gap-4">
                <flux:input wire:model="command" :label="__('Command')" placeholder="php8.5 /home/example/example.com/current/artisan backup:run" class="font-mono" required />

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:select wire:model="user" :label="__('User')">
                        @foreach ($server->systemUsers() as $systemUser)
                            @continue($systemUser === 'root' && auth()->user()->cannot('runAsRoot', $server))
                            <flux:select.option :value="$systemUser">{{ $systemUser }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="frequency" :label="__('Frequency')">
                        @foreach ($this::FREQUENCIES as $expression => $label)
                            <flux:select.option :value="$expression">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($frequency === 'custom')
                        <flux:input wire:model="custom_frequency" :label="__('Cron expression')" placeholder="*/5 * * * *" class="font-mono" />
                    @endif
                </div>

                <div>
                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Schedule job') }}</flux:button>
                </div>
            </form>
            </x-panel>

            <x-panel icon="calendar-days" :heading="__('Scheduled jobs')" flush>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Command') }}</flux:table.column>
                    <flux:table.column>{{ __('User') }}</flux:table.column>
                    <flux:table.column>{{ __('Frequency') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->jobs as $job)
                        <flux:table.row :key="$job->id">
                            <flux:table.cell class="max-w-md truncate font-mono text-xs" :title="$job->command">{{ $job->command }}</flux:table.cell>
                            <flux:table.cell>{{ $job->user }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $job->frequency }}</flux:table.cell>
                            <flux:table.cell><x-status :color="$job->status->color()" :label="$job->status->label()" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $job->id }})" wire:confirm.danger="{{ __('Remove this scheduled job?') }}" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5">{{ __('No scheduled jobs yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
            </x-panel>
        </div>
    </x-pages::servers.layout>
</div>
