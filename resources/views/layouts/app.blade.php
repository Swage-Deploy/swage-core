<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <div class="mx-auto w-full max-w-7xl">
            @if ($impersonator = app(App\Actions\Users\Impersonation::class)->impersonator())
                <flux:callout icon="user-circle" color="amber" class="mb-6">
                    <flux:callout.heading>{{ __('You are signed in as :name', ['name' => auth()->user()->name]) }}</flux:callout.heading>
                    <flux:callout.text>
                        @if (session('impersonation-blocked'))
                            {{ __('That page is part of their account settings, which are not available while you are signed in as them.') }}
                        @else
                            {{ __('You see Swage as they do. Their account settings are not available. Signed in from :admin.', ['admin' => $impersonator->email]) }}
                        @endif
                    </flux:callout.text>
                    <x-slot name="actions">
                        <form method="POST" action="{{ route('impersonation.stop') }}">
                            @csrf
                            <flux:button type="submit" size="sm" icon="arrow-uturn-left">{{ __('Stop impersonating') }}</flux:button>
                        </form>
                    </x-slot>
                </flux:callout>
            @endif

            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
