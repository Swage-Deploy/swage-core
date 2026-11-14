@props([
    'label',
    'value',
    'detail' => null,
    'percent' => 0,
    'high' => __('High'),
    'history' => [],
    'max' => 100,
    'meter' => true,
])

@php
    $level = match (true) {
        ! $meter => 'normal',
        $percent >= 90 => 'critical',
        $percent >= 75 => 'warning',
        default => 'normal',
    };
@endphp

<div {{ $attributes->class('flex min-w-0 flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/8') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
        @if ($level !== 'normal')
            <span @class([
                'inline-flex items-center gap-1 text-xs font-medium',
                'text-red-600 dark:text-red-400' => $level === 'critical',
                'text-amber-600 dark:text-amber-400' => $level === 'warning',
            ])>
                <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                {{ $high }}
            </span>
        @endif
    </div>

    <div>
        <div class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $value }}</div>
        @if ($detail)
            <div class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $detail }}</div>
        @endif
    </div>

    @if ($meter)
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/5" role="meter" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($percent) }}">
        <div @class([
            'h-full rounded-full',
            'bg-ember-500' => $level === 'normal',
            'bg-amber-500' => $level === 'warning',
            'bg-red-500' => $level === 'critical',
        ]) style="width: {{ round(max(0, min(100, $percent)), 1) }}%"></div>
    </div>
    @endif

    @if (count($history))
        <x-sparkline :points="$history" :max="$max" class="mt-auto pt-1" />
    @endif
</div>
