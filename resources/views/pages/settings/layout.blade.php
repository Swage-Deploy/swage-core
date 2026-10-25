<div class="flex flex-col gap-6">
    <flux:navbar scrollable aria-label="{{ __('Settings') }}" class="-ms-2 border-b border-zinc-200 dark:border-white/8">
        @unlessimpersonating
            <flux:navbar.item icon="user-circle" :href="route('profile.edit')" :current="request()->pageRouteIs('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navbar.item>
            <flux:navbar.item icon="shield-check" :href="route('security.edit')" :current="request()->pageRouteIs('security.edit')" wire:navigate>{{ __('Security') }}</flux:navbar.item>
        @endimpersonating
        <flux:navbar.item icon="swatch" :href="route('appearance.edit')" :current="request()->pageRouteIs('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navbar.item>
        @unlessimpersonating
            <flux:navbar.item icon="folder-git-2" :href="route('source-control.edit')" :current="request()->pageRouteIs('source-control.edit')" wire:navigate>{{ __('Source control') }}</flux:navbar.item>
        @endimpersonating
    </flux:navbar>

    <x-panel :heading="$heading ?? ''" :description="$subheading ?? null" class="max-w-3xl">
        <div class="w-full max-w-lg">
            {{ $slot }}
        </div>
    </x-panel>
</div>
