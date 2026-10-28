@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes->class('[&_[data-flux-brand-name]]:font-semibold [&_[data-flux-brand-name]]:tracking-tight') }}>
        <x-slot name="logo" class="h-8">
            <x-app-logo-icon class="h-5 w-auto" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8">
            <x-app-logo-icon class="h-5 w-auto" />
        </x-slot>
    </flux:brand>
@endif
