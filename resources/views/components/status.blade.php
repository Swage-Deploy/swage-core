@props([
    'color' => 'zinc',
    'label' => null,
])

@php
    $dot = match ($color) {
        'green' => 'bg-emerald-500 shadow-emerald-500/70',
        'amber' => 'bg-ember-500 shadow-ember-500/80 animate-ember',
        'red' => 'bg-red-500 shadow-red-500/70',
        'sky' => 'bg-sky-500 shadow-sky-500/70',
        default => 'bg-zinc-400 shadow-transparent dark:bg-zinc-500',
    };
    $text = match ($color) {
        'green' => 'text-emerald-700 dark:text-emerald-400',
        'amber' => 'text-ember-700 dark:text-ember-400',
        'red' => 'text-red-700 dark:text-red-400',
        'sky' => 'text-sky-700 dark:text-sky-400',
        default => 'text-zinc-500 dark:text-zinc-400',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-2 text-xs font-medium whitespace-nowrap', $text]) }}>
    <span class="size-2 shrink-0 rounded-full shadow-[0_0_8px_1px] {{ $dot }}"></span>
    @if ($label)
        <span>{{ $label }}</span>
    @endif
</span>
