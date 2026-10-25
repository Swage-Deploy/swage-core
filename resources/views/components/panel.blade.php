@props([
    'heading' => null,
    'description' => null,
    'icon' => null,
    'flush' => false,
])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-white/8 dark:bg-zinc-900/70') }}>
    @if ($heading || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-white/8">
            <div class="flex min-w-0 items-start gap-3">
                @if ($icon)
                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-ember-500/10 text-ember-600 ring-1 ring-ember-500/20 dark:text-ember-400">
                        <flux:icon :name="$icon" variant="mini" class="size-4" />
                    </span>
                @endif
                <div class="min-w-0">
                    <flux:heading>{{ $heading }}</flux:heading>
                    @if ($description)
                        <flux:text class="mt-0.5">{{ $description }}</flux:text>
                    @endif
                </div>
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['p-5' => ! $flush, '[&_[data-flux-table]_th:first-child]:ps-5 [&_[data-flux-table]_td:first-child]:ps-5 [&_[data-flux-table]_th:last-child]:pe-5 [&_[data-flux-table]_td:last-child]:pe-5' => $flush])>
        {{ $slot }}
    </div>
</section>
