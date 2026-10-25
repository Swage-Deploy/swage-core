@props([
    'lines',
    'title' => null,
])

@php($overriddenCount = collect($lines)->where('overridden', true)->count())

<div {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950') }}>
    <div class="flex items-center justify-between gap-4 border-b border-white/8 bg-zinc-900 px-4 py-2.5">
        <span class="truncate font-mono text-xs text-zinc-400">{{ $title }}</span>
        @if ($overriddenCount > 0)
            <span class="flex shrink-0 items-center gap-1.5 text-xs text-ember-400">
                <flux:icon.arrow-uturn-left variant="micro" />
                {{ trans_choice(':count line overridden|:count lines overridden', $overriddenCount) }}
            </span>
        @endif
    </div>
    <pre class="max-h-[32rem] overflow-auto py-3 font-mono text-xs leading-relaxed text-zinc-300">@foreach ($lines as $index => $line)<span @class(['flex px-4', 'bg-ember-500/10 text-zinc-500 line-through decoration-ember-500/60' => $line['overridden']])><span class="w-9 shrink-0 pe-3 text-end text-zinc-600 select-none">{{ $index + 1 }}</span><span class="whitespace-pre-wrap">{{ $line['line'] === '' ? ' ' : $line['line'] }}</span></span>@endforeach</pre>
</div>
