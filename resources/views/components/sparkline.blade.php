@props([
    // Each point is ['at' => Carbon, 'value' => float, 'label' => string].
    'points',
    'max' => 100,
    'hours' => 24,
])

@php
    $end = now();
    $start = $end->copy()->subHours($hours);
    $span = max(1, $end->getTimestamp() - $start->getTimestamp());
    $max = max($max, 0.0001);

    $plotted = collect($points)->map(fn (array $point): array => [
        'x' => round(max(0, min(1, ($point['at']->getTimestamp() - $start->getTimestamp()) / $span)) * 100, 3),
        'y' => round(32 - max(0, min(1, $point['value'] / $max)) * 30, 3),
        'at' => $point['at'],
        'label' => $point['label'],
    ])->values();

    // A reading missed for more than a few intervals, such as while the server was down, breaks the line.
    $segments = [];
    foreach ($plotted as $index => $point) {
        if ($index === 0 || $point['at']->diffInMinutes($plotted[$index - 1]['at'], true) > 16) {
            $segments[] = [];
        }
        $segments[array_key_last($segments)][] = $point;
    }
@endphp

<div
    {{ $attributes->class('relative') }}
    x-data="{ points: @js($plotted->map(fn ($point) => ['x' => $point['x'], 'y' => $point['y'], 'text' => $point['at']->format('D H:i').' · '.$point['label']])), active: null }"
    x-on:mousemove="
        const fraction = ($event.offsetX / $el.clientWidth) * 100;
        active = points.length ? points.reduce((best, point) => Math.abs(point.x - fraction) < Math.abs(best.x - fraction) ? point : best) : null;
    "
    x-on:mouseleave="active = null"
>
    <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="block h-10 w-full overflow-visible" aria-hidden="true">
        <line x1="0" y1="32" x2="100" y2="32" class="stroke-zinc-200 dark:stroke-white/10" stroke-width="1" vector-effect="non-scaling-stroke" />
        @foreach ($segments as $segment)
            @if (count($segment) > 1)
                <path d="M{{ $segment[0]['x'] }},32 L{{ collect($segment)->map(fn ($point) => $point['x'].','.$point['y'])->implode(' L') }} L{{ end($segment)['x'] }},32 Z" class="fill-ember-500/10" />
                <polyline points="{{ collect($segment)->map(fn ($point) => $point['x'].','.$point['y'])->implode(' ') }}" fill="none" class="stroke-ember-500" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            @else
                <line x1="{{ $segment[0]['x'] }}" y1="{{ $segment[0]['y'] }}" x2="{{ $segment[0]['x'] }}" y2="{{ $segment[0]['y'] }}" class="stroke-ember-500" stroke-width="4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            @endif
        @endforeach
    </svg>

    <template x-if="active">
        <div>
            <div class="pointer-events-none absolute inset-y-0 w-px bg-zinc-400/60 dark:bg-white/30" x-bind:style="`left: ${active.x}%`"></div>
            <div class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-ember-500 ring-2 ring-white dark:ring-zinc-900" x-bind:style="`left: ${active.x}%; top: ${active.y / 32 * 100}%`"></div>
            <div class="pointer-events-none absolute -top-7 z-10 -translate-x-1/2 rounded-md bg-zinc-900 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-white shadow-sm dark:bg-white dark:text-zinc-900" x-bind:style="`left: min(max(${active.x}%, 4rem), calc(100% - 4rem))`" x-text="active.text"></div>
        </div>
    </template>
</div>
