<x-layouts::auth :title="config('app.name')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="config('app.name')" :description="__('Provisions, deploys and manages this team\'s servers.')" />

        @auth
            <flux:button :href="route('dashboard')" variant="primary" icon:trailing="arrow-right" class="w-full" wire:navigate>
                {{ __('Open dashboard') }}
            </flux:button>
        @else
            <flux:button :href="route('login')" variant="primary" class="w-full" wire:navigate>
                {{ __('Log in') }}
            </flux:button>

            @if (config('swage.registration'))
                <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Don\'t have an account?') }}
                    <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
                </div>
            @endif
        @endauth
    </div>
</x-layouts::auth>
