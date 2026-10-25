@props(['integrations', 'current'])

@php
    use App\Plugins\HasPluginTab;
    use Illuminate\Support\Str;
@endphp

@if (count($integrations) > 0)
    <flux:navbar scrollable aria-label="{{ __('Plugins') }}" class="-mb-px border-b border-zinc-200 dark:border-white/8">
        @foreach ($integrations as $id => $integration)
            <flux:navbar.item
                :icon="$integration instanceof HasPluginTab ? $integration->tabIcon() : 'puzzle-piece'"
                :current="$id === $current"
                wire:click="$set('plugin', '{{ $id }}')"
                wire:key="plugin-tab-{{ $id }}"
            >
                {{ $integration instanceof HasPluginTab ? $integration->tabLabel() : Str::headline($id) }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>
@endif
