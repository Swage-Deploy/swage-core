@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes->class('[&_[data-flux-brand-name]]:font-semibold [&_[data-flux-brand-name]]:tracking-tight') }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-linear-to-br from-ember-400 to-ember-700 text-zinc-950 shadow-[0_0_18px_-4px] shadow-ember-500/60">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-linear-to-br from-ember-400 to-ember-700 text-zinc-950 shadow-[0_0_18px_-4px] shadow-ember-500/60">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
