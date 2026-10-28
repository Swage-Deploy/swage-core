<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
        <div class="relative isolate flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden p-6 md:p-10">
            <div class="provision-grid absolute inset-0 -z-10 text-zinc-900 dark:text-white"></div>
            <div class="absolute bottom-0 left-1/2 -z-10 h-80 w-[48rem] -translate-x-1/2 translate-y-1/2 rounded-full bg-ember-500/20 blur-3xl"></div>

            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 font-medium" wire:navigate>
                    <x-app-logo-icon class="h-12 w-auto drop-shadow-[0_0_24px_rgb(236_92_14/0.45)]" />
                    <span class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6 rounded-2xl border border-zinc-200 bg-white/80 p-8 shadow-xl backdrop-blur dark:border-white/8 dark:bg-zinc-900/80 dark:shadow-black/40">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
