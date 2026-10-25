<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="provision-glow min-h-screen bg-zinc-50 bg-fixed antialiased dark:bg-zinc-950">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-white/70 backdrop-blur dark:border-white/8 dark:bg-black/30">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="server-stack" :href="route('dashboard')" :current="request()->routeIs('dashboard', 'servers.index', 'servers.create')" wire:navigate>
                        {{ __('Servers') }}
                    </flux:sidebar.item>
                    @unlessimpersonating
                        <flux:sidebar.item icon="link" :href="route('source-control.edit')" :current="request()->routeIs('source-control.edit')" wire:navigate>
                            {{ __('Source control') }}
                        </flux:sidebar.item>
                    @endimpersonating
                    @can('administer')
                        <flux:sidebar.item icon="arrow-down-tray" :href="route('updates')" :current="request()->routeIs('updates')" wire:navigate>
                            {{ __('Updates') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('users')" :current="request()->routeIs('users')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="puzzle-piece" :href="route('plugins')" :current="request()->routeIs('plugins', 'source-control.configure')" wire:navigate>
                            {{ __('Plugins') }}
                        </flux:sidebar.item>

                    @endcan
                </flux:sidebar.group>

                @php($sidebarServers = auth()->user()->accessibleServers()->orderBy('name')->get(['id', 'name', 'status']))

                @if ($sidebarServers->isNotEmpty())
                    <flux:sidebar.group :heading="__('Your servers')" class="mt-4 grid">
                        @foreach ($sidebarServers as $sidebarServer)
                            <flux:sidebar.item :href="route('servers.show', $sidebarServer)" :current="request()->routeIs('servers.*') && request()->route('server')?->is($sidebarServer)" wire:navigate>
                                {{-- The status dot sits in the icon slot, outside the label's truncation, so its glow is not clipped. --}}
                                <x-slot:icon>
                                    <span class="flex size-4 items-center justify-center">
                                        <x-status :color="$sidebarServer->status->color()" />
                                    </span>
                                </x-slot:icon>
                                {{ $sidebarServer->name }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route(app(App\Actions\Users\Impersonation::class)->isImpersonating() ? 'appearance.edit' : 'profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @include('partials.confirm-modal')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
