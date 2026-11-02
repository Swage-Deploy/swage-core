<?php

use App\Actions\Servers\AddSshKey;
use App\Actions\Servers\ReadSshAccess;
use App\Actions\Servers\RemoveSshKey;
use App\Actions\Servers\SetSshAccess;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Servers\SshAccess;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public string $user = '';

    public string $name = '';

    public string $public_key = '';

    /**
     * What the server last reported, from SshAccess::toArray().
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $access = null;

    public ?string $loadError = null;

    public function mount(): void
    {
        $this->authorize('view', $this->server);

        $this->user = $this->server->sites()->orderBy('system_user')->value('system_user') ?? 'root';
    }

    public function render(): mixed
    {
        return $this->view()->title(__('SSH').' · '.$this->server->name);
    }

    /**
     * Read the users' keys and sshd's config from the server.
     */
    public function load(ReadSshAccess $readSshAccess): void
    {
        $this->authorize('view', $this->server);

        $this->loadError = null;

        try {
            $this->access = $readSshAccess->handle($this->server)->toArray();
        } catch (Throwable $exception) {
            $this->loadError = $exception->getMessage() ?: __('Unable to reach the server.');
        }

        unset($this->sshAccess);
    }

    #[Computed]
    public function sshAccess(): ?SshAccess
    {
        return $this->access ? SshAccess::fromArray($this->access) : null;
    }

    #[Computed]
    public function sites(): Collection
    {
        return $this->server->sites()->orderBy('system_user')->get();
    }

    public function add(AddSshKey $addSshKey, ReadSshAccess $readSshAccess): void
    {
        $this->authorize('update', $this->server);

        if ($this->user === 'root') {
            $this->authorize('runAsRoot', $this->server);
        }

        if (! $this->sshAccess) {
            return;
        }

        $added = $this->change(fn () => $addSshKey->handle($this->server, [
            'user' => $this->user,
            'name' => $this->name,
            'public_key' => $this->public_key,
        ], $this->sshAccess), __('SSH key added.'), $readSshAccess);

        if ($added) {
            $this->reset('name', 'public_key');
        }
    }

    public function remove(string $user, int $index, RemoveSshKey $removeSshKey, ReadSshAccess $readSshAccess): void
    {
        $this->authorize($user === 'root' ? 'runAsRoot' : 'update', $this->server);

        $key = $this->sshAccess?->keysFor($user)[$index] ?? abort(404);

        if ($key->is($this->server->ssh_public_key)) {
            Flux::toast(variant: 'danger', text: __('Swage manages the server with this key, so it can\'t be removed.'));

            return;
        }

        $this->change(fn () => $removeSshKey->handle($this->server, $user, $key), __('SSH key removed.'), $readSshAccess);
    }

    public function toggle(string $user, SetSshAccess $setSshAccess, ReadSshAccess $readSshAccess): void
    {
        $this->authorize('update', $this->server);

        if (! $this->sshAccess || $this->sshAccess->deniedElsewhere($user)) {
            return;
        }

        $enabled = ! $this->sshAccess->allows($user);

        $this->change(fn () => $setSshAccess->handle($this->server, $user, $enabled), $enabled
            ? __(':user can sign in over SSH again.', ['user' => $user])
            : __(':user can no longer sign in over SSH.', ['user' => $user]), $readSshAccess);
    }

    /**
     * Make a change on the server, then read it back so the page shows what the server now holds.
     */
    protected function change(Closure $change, string $success, ReadSshAccess $readSshAccess): bool
    {
        try {
            $change();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception instanceof ScriptFailedException
                ? __('The change failed on the server. See the server\'s activity for its output.')
                : __('Unable to reach the server.'));
        }

        $this->load($readSshAccess);

        if (isset($exception)) {
            return false;
        }

        Flux::toast(variant: 'success', text: $success);

        return true;
    }
}; ?>

<div wire:init="load">
    <x-pages::servers.layout :server="$server">
        <div class="flex flex-col gap-6">
            @can('update', $server)
                <x-panel icon="key" :heading="__('Add an SSH key')" :description="__('Lets the key\'s holder sign in over SSH as a user on this server.')">
                    <form wire:submit="add" class="flex max-w-2xl flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:select wire:model="user" :label="__('User')">
                                @foreach ($server->sshUsers() as $sshUser)
                                    @continue($sshUser === 'root' && auth()->user()->cannot('runAsRoot', $server))
                                    <flux:select.option :value="$sshUser">{{ $sshUser }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="name" :label="__('Name')" :description="__('Leave empty to use the key\'s comment.')" placeholder="laptop" />
                        </div>

                        <flux:textarea wire:model="public_key" :label="__('Public key')" rows="3" spellcheck="false" placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... you@laptop" class="font-mono text-xs" required />

                        <div>
                            <flux:button type="submit" variant="primary" icon="plus" :disabled="! $this->sshAccess">{{ __('Add key') }}</flux:button>
                        </div>
                    </form>
                </x-panel>
            @endcan

            @if ($loadError)
                <flux:callout icon="x-circle" color="red">
                    <flux:callout.heading>{{ __('Unable to read SSH access from the server') }}</flux:callout.heading>
                    <flux:callout.text>{{ $loadError }}</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="load">{{ __('Try again') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @elseif (! $this->sshAccess)
                <x-panel>
                    <div class="flex items-center gap-2 text-sm text-zinc-500">
                        <flux:icon.loading class="size-4" />
                        {{ __('Reading keys from the server...') }}
                    </div>
                </x-panel>
            @else
                <x-panel icon="shield-check" heading="root" :description="__('Swage signs in as root to manage the server, so root can always sign in over SSH with a key.')" flush>
                    @include('partials.ssh-keys', ['sshUser' => 'root', 'canManage' => auth()->user()->can('runAsRoot', $server)])
                </x-panel>

                @foreach ($this->sites as $site)
                    @php($sshUser = $site->system_user)
                    <x-panel wire:key="ssh-{{ $sshUser }}" icon="user" :heading="$sshUser" :description="$site->domain" flush>
                        <x-slot:actions>
                            <flux:switch :checked="$this->sshAccess->allows($sshUser)" wire:click="toggle('{{ $sshUser }}')" :label="__('SSH access')" align="left"
                                :disabled="auth()->user()->cannot('update', $server) || $this->sshAccess->deniedElsewhere($sshUser)" />
                        </x-slot:actions>

                        @if ($this->sshAccess->deniedElsewhere($sshUser))
                            <flux:callout icon="no-symbol" color="zinc" class="m-5">
                                <flux:callout.text>{{ __('The server\'s own sshd config stops this user signing in over SSH, so Swage can\'t turn it back on.') }}</flux:callout.text>
                            </flux:callout>
                        @elseif (! $this->sshAccess->allows($sshUser))
                            <flux:callout icon="no-symbol" color="zinc" class="m-5">
                                <flux:callout.text>{{ __('This user cannot sign in over SSH, even with the keys below. The site keeps running and deploying as usual.') }}</flux:callout.text>
                            </flux:callout>
                        @endif

                        @include('partials.ssh-keys', ['sshUser' => $sshUser, 'canManage' => auth()->user()->can('update', $server)])
                    </x-panel>
                @endforeach
            @endif
        </div>
    </x-pages::servers.layout>
</div>
