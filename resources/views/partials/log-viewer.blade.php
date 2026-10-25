@php($selected = $this->selectedLog)

<div class="flex flex-col gap-4" wire:init="readLog" @if ($follow) wire:poll.{{ $this::FOLLOW_INTERVAL_SECONDS }}s="readLog" @endif>
    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="log" :label="__('Log')" class="min-w-56 flex-1">
            @foreach (collect($this->logs)->groupBy('group') as $group => $logs)
                <optgroup label="{{ $group }}">
                    @foreach ($logs as $log)
                        <option value="{{ $log->key }}">{{ $log->label }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="lines" :label="__('Lines')" class="w-28">
            @foreach ($this::LINE_COUNTS as $count)
                <option value="{{ $count }}">{{ $count }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.500ms="filter" :label="__('Filter')" icon="magnifying-glass" :placeholder="__('Lines containing…')" class="min-w-48 flex-1" clearable />

        <div class="flex items-center gap-3 pb-2">
            <flux:switch wire:model.live="follow" :label="__('Follow')" :title="__('Reads the log every :seconds seconds for up to :minutes minutes.', ['seconds' => $this::FOLLOW_INTERVAL_SECONDS, 'minutes' => $this::FOLLOW_MINUTES])" />
            <flux:button size="sm" icon="arrow-path" wire:click="readLog">{{ __('Refresh') }}</flux:button>
        </div>
    </div>

    @error('filter')
        <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
    @enderror

    @if ($readError)
        <flux:callout icon="exclamation-triangle" color="red">
            <flux:callout.heading>{{ __('The log could not be read') }}</flux:callout.heading>
            <flux:callout.text>{{ $readError }}</flux:callout.text>
        </flux:callout>
    @elseif ($output === false)
        <flux:callout icon="information-circle">
            <flux:callout.heading>{{ __('Nothing has been logged yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __(':path does not exist on the server yet.', ['path' => $selected->location()]) }}
                @if ($selected->scheduledJobId)
                    {{ __('Scheduled jobs installed before logging was added discard their output until they are reinstalled.') }}
                @endif
            </flux:callout.text>
            @if ($selected->scheduledJobId)
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="reinstallScheduledJob">{{ __('Reinstall scheduled job') }}</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @elseif ($output === null)
        <x-terminal :title="$selected->location()">{{ __('Reading log…') }}</x-terminal>
    @else
        <x-terminal
            :title="$source ?: $selected->location()"
            wire:key="log-output-{{ md5($selected->key.$output) }}"
        >{{ $output !== '' ? $output : ($filter !== '' ? __('No lines match the filter.') : __('The log is empty.')) }}</x-terminal>
    @endif
</div>
