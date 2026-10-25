@props([
    'allocation',
    'memoryMb',
    'compact' => false,
])

@php
    $colors = [
        'PHP-FPM' => 'bg-ember-500',
        'MySQL' => 'bg-sky-500',
        'Redis' => 'bg-rose-500',
        'System' => 'bg-zinc-300 dark:bg-zinc-600',
    ];
@endphp

<div {{ $attributes->class('flex flex-col gap-2.5') }}>
    <div @class(['flex w-full gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/5', 'h-1.5' => $compact, 'h-3' => ! $compact])>
        @foreach ($allocation as $slice)
            @if ($slice['mb'] > 0)
                <div class="{{ $colors[$slice['service']] ?? 'bg-zinc-400' }} first:rounded-s-full last:rounded-e-full" style="width: {{ round($slice['mb'] / max(1, $memoryMb) * 100, 2) }}%" title="{{ $slice['service'] }}: {{ Number::fileSize($slice['mb'] * 1024 * 1024) }}"></div>
            @endif
        @endforeach
    </div>

    @unless ($compact)
        <div class="flex flex-wrap gap-x-5 gap-y-1.5">
            @foreach ($allocation as $slice)
                <div class="flex items-center gap-2 text-xs">
                    <span class="size-2 rounded-sm {{ $colors[$slice['service']] ?? 'bg-zinc-400' }}"></span>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $slice['service'] }}</span>
                    <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ Number::fileSize($slice['mb'] * 1024 * 1024, precision: 1) }}</span>
                </div>
            @endforeach
        </div>
    @endunless
</div>
