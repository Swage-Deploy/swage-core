@props([
    'icon' => 'sparkles',
    'heading',
])

<div {{ $attributes->class('relative isolate flex flex-col items-center gap-3 overflow-hidden px-6 py-12 text-center') }}>
    <div class="provision-grid absolute inset-0 -z-10 text-zinc-900 dark:text-white"></div>
    <span class="flex size-12 items-center justify-center rounded-xl bg-ember-500/10 text-ember-600 ring-1 ring-ember-500/25 dark:text-ember-400">
        <flux:icon :name="$icon" class="size-6" />
    </span>
    <flux:heading size="lg">{{ $heading }}</flux:heading>
    @if ($slot->isNotEmpty())
        <flux:text class="max-w-md">{{ $slot }}</flux:text>
    @endif
    @isset($actions)
        <div class="mt-2 flex gap-2">{{ $actions }}</div>
    @endisset
</div>
