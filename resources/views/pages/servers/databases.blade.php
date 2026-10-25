<?php

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Jobs\DeleteDatabase;
use App\Jobs\DeleteDatabaseUser;
use App\Jobs\RestoreDatabase;
use App\Jobs\SyncDatabaseAccess;
use App\Jobs\SyncDatabaseUser;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Server $server;

    public string $name = '';

    public string $engine = '';

    public string $username = '';

    public string $userEngine = '';

    public string $accessAddress = '';

    public string $password = '';

    /** @var list<int> */
    public array $userDatabases = [];

    public ?int $editingUserId = null;

    public ?int $restoringDatabaseId = null;

    public ?TemporaryUploadedFile $restoreFile = null;

    public string $restoreConfirmation = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);
        abort_unless($this->server->hasDatabase(), 404);

        $this->engine = $this->server->defaultDatabaseEngine()->value;
    }

    /**
     * Validation rules that keep an engine to the ones the server runs.
     *
     * @return list<mixed>
     */
    protected function engineRules(): array
    {
        return ['required', Rule::in(array_column($this->server->databaseEngines(), 'value'))];
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Databases').' · '.$this->server->name);
    }

    /**
     * @return Collection<int, Database>
     */
    #[Computed]
    public function databases(): Collection
    {
        return $this->server->databases()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, DatabaseUser>
     */
    #[Computed]
    public function users(): Collection
    {
        return $this->server->databaseUsers()->with('databases')->orderBy('username')->get();
    }

    public function createDatabase(): void
    {
        $this->authorize('update', $this->server);

        $validated = $this->validate([
            'engine' => $this->engineRules(),
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/D', Database::notSystemDatabaseRule(),
                Rule::unique('databases')->where('server_id', $this->server->id)->where('engine', $this->engine)],
        ], [
            'name.not_regex' => __('This name is reserved by the database server. Choose another.'),
        ]);

        CreateDatabase::dispatch($this->server->databases()->create([...$validated, 'status' => ResourceStatus::Pending]));

        $this->reset('name');
        Flux::toast(variant: 'success', text: __('Creating database.'));
    }

    public function deleteDatabase(int $databaseId): void
    {
        $this->authorize('update', $this->server);

        $database = $this->server->databases()->findOrFail($databaseId);
        $database->update(['status' => ResourceStatus::Removing]);

        DeleteDatabase::dispatch($database);
    }

    /**
     * The database being restored from a file.
     */
    #[Computed]
    public function restoringDatabase(): ?Database
    {
        return $this->restoringDatabaseId ? $this->server->databases()->find($this->restoringDatabaseId) : null;
    }

    public function startRestore(int $databaseId): void
    {
        $this->authorize('transferDatabases', $this->server);

        abort_unless($this->server->databases()->findOrFail($databaseId)->isTransferable(), 404);

        $this->restoringDatabaseId = $databaseId;
        $this->reset('restoreFile', 'restoreConfirmation');
        $this->resetValidation();

        Flux::modal('restore-database')->show();
    }

    /**
     * Replace the database with the uploaded dump. The modal asks for the database's name, then the button asks again.
     */
    public function restoreDatabase(): void
    {
        $this->authorize('transferDatabases', $this->server);

        $database = $this->server->databases()->findOrFail($this->restoringDatabaseId);

        abort_unless($database->isTransferable(), 404);

        $this->validate([
            'restoreFile' => ['required', 'file', 'extensions:sql,gz', 'max:524288'],
            'restoreConfirmation' => ['required', Rule::in([$database->name])],
        ], [
            'restoreFile.extensions' => __('Choose a .sql or .sql.gz file.'),
            'restoreConfirmation.in' => __('Type :name to confirm.', ['name' => $database->name]),
        ]);

        $path = $this->restoreFile->store('database-restores', 'local');

        $database->update(['status' => ResourceStatus::Restoring]);

        RestoreDatabase::dispatch($database, $path);

        $this->reset('restoringDatabaseId', 'restoreFile', 'restoreConfirmation');
        Flux::modal('restore-database')->close();
        Flux::toast(variant: 'success', text: __('Restoring database. Follow along in the activity log.'));
    }

    /**
     * The user's other servers that may be allowed to connect, with the address each would connect from.
     *
     * @return SupportCollection<int, array{server: Server, address: ?string}>
     */
    #[Computed]
    public function accessCandidates(): SupportCollection
    {
        return $this->server->user->servers()->whereKeyNot($this->server->id)->orderBy('name')->get()
            ->map(fn (Server $client): array => ['server' => $client, 'address' => $this->server->databaseAccessAddressFor($client)]);
    }

    /**
     * Allow an IP address or CIDR range to connect to the database server's databases.
     */
    public function allowAccess(): void
    {
        $this->authorize('update', $this->server);
        abort_unless($this->server->isDatabaseServer(), 404);

        $this->validate([
            'accessAddress' => ['required', 'string', 'max:64', function (string $attribute, string $value, Closure $fail): void {
                [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
                $maximum = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

                if (! filter_var($ip, FILTER_VALIDATE_IP) || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $maximum))) {
                    $fail(__('Enter an IP address, such as 10.0.0.2, or a range, such as 10.0.0.0/24.'));
                } elseif ($prefix !== null && (int) $prefix < 8) {
                    $fail(__('Allow specific addresses or a private range, not a large part of the internet.'));
                } elseif (in_array($value, $this->server->databaseAccess(), true)) {
                    $fail(__('This address is already allowed.'));
                }
            }],
        ]);

        $this->updateAccess([...$this->server->databaseAccess(), $this->accessAddress]);
        $this->reset('accessAddress');
    }

    /**
     * Allow one of the user's other servers to connect.
     */
    public function allowServer(int $serverId): void
    {
        $candidate = $this->accessCandidates->firstWhere('server.id', $serverId);

        abort_if($candidate === null || $candidate['address'] === null, 404);

        $this->accessAddress = $candidate['address'];
        $this->allowAccess();
    }

    /**
     * Stop an address from connecting.
     */
    public function revokeAccess(string $address): void
    {
        $this->authorize('update', $this->server);
        abort_unless($this->server->isDatabaseServer(), 404);

        $this->updateAccess(array_values(array_diff($this->server->databaseAccess(), [$address])));
    }

    /**
     * @param  list<string>  $addresses
     */
    protected function updateAccess(array $addresses): void
    {
        $this->server->update(['database_access' => $addresses]);

        SyncDatabaseAccess::dispatch($this->server);

        Flux::toast(variant: 'success', text: __('Updating the firewall. Follow along in the activity log.'));
    }

    /**
     * Databases on one engine cannot be given to a user on another, so switching engines clears the selection.
     */
    public function updatedUserEngine(): void
    {
        $this->userDatabases = [];
    }

    public function newUser(): void
    {
        $this->reset('editingUserId', 'username', 'userDatabases');
        $this->userEngine = $this->server->defaultDatabaseEngine()->value;
        $this->password = Str::password(24, symbols: false);
        $this->resetValidation();

        Flux::modal('database-user')->show();
    }

    public function editUser(int $userId): void
    {
        $user = $this->server->databaseUsers()->with('databases')->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->username = $user->username;
        $this->userEngine = $user->engine->value;
        $this->password = '';
        $this->userDatabases = $user->databases->modelKeys();
        $this->resetValidation();

        Flux::modal('database-user')->show();
    }

    public function saveUser(): void
    {
        $this->authorize('update', $this->server);

        $user = $this->editingUserId
            ? $this->server->databaseUsers()->findOrFail($this->editingUserId)
            : $this->server->databaseUsers()->make();

        // Accounts are synced by name and engine, so changing either would leave the old account behind with its access.
        if ($user->exists) {
            $this->username = $user->username;
            $this->userEngine = $user->engine->value;
        }

        $validated = $this->validate([
            'userEngine' => $this->engineRules(),
            'username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/D', 'not_regex:/^pg_/i', Rule::notIn(['root', 'postgres', config('swage.user')]),
                Rule::unique('database_users')->where('server_id', $this->server->id)->where('engine', $this->userEngine)->ignore($this->editingUserId)],
            'password' => [$this->editingUserId ? 'nullable' : 'required', 'string', 'min:12', 'max:100', 'regex:/^[A-Za-z0-9!#%*+,.\/:=?@^_{}~-]+$/D'],
            'userDatabases' => ['array'],
            // A user can only be given databases on its own engine.
            'userDatabases.*' => [Rule::exists('databases', 'id')->where('server_id', $this->server->id)->where('engine', $this->userEngine)],
        ], [
            'username.not_regex' => __('Names starting with pg_ are reserved by PostgreSQL.'),
            'password.regex' => __('The password may not contain quotes, spaces, backslashes, dollar signs or backticks.'),
        ]);

        $user->fill([
            'engine' => $validated['userEngine'],
            'username' => $validated['username'],
            'status' => ResourceStatus::Pending,
            ...(filled($validated['password']) ? ['password' => $validated['password']] : []),
        ])->save();

        $user->databases()->sync($validated['userDatabases']);

        SyncDatabaseUser::dispatch($user);

        Flux::modal('database-user')->close();
        Flux::toast(variant: 'success', text: __('Saving database user.'));
    }

    public function deleteUser(int $userId): void
    {
        $this->authorize('update', $this->server);

        $user = $this->server->databaseUsers()->findOrFail($userId);
        $user->update(['status' => ResourceStatus::Removing]);

        DeleteDatabaseUser::dispatch($user);
    }
}; ?>

<div @if ($this->databases->contains(fn ($db) => $db->status->isBusy()) || $this->users->contains(fn ($user) => $user->status->isBusy())) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        @php($multipleEngines = count($server->databaseEngines()) > 1)
        <div class="flex flex-col gap-6">
            <x-panel icon="circle-stack" :heading="__('Databases')" :description="__(':engines databases on this server.', ['engines' => collect($server->databaseEngines())->map->labelWithVersion()->implode(' & ')])" flush>
                <x-slot:actions>
                    <form wire:submit="createDatabase" class="flex flex-col items-end gap-1">
                        <div class="flex items-center gap-2">
                            @if ($multipleEngines)
                                <flux:select wire:model="engine" size="sm" :aria-label="__('Engine')" class="w-36">
                                    @foreach ($server->databaseEngines() as $databaseEngine)
                                        <flux:select.option :value="$databaseEngine->value">{{ $databaseEngine->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            <flux:input wire:model="name" size="sm" :aria-label="__('New database')" :placeholder="__('new_database')" class="w-44 font-mono" />
                            <flux:button type="submit" size="sm" variant="primary" icon="plus">{{ __('Create') }}</flux:button>
                        </div>
                        <flux:error name="name" />
                    </form>
                </x-slot:actions>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        @if ($multipleEngines)
                            <flux:table.column>{{ __('Engine') }}</flux:table.column>
                        @endif
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->databases as $database)
                            <flux:table.row :key="'db-'.$database->id">
                                <flux:table.cell class="font-mono">{{ $database->name }}</flux:table.cell>
                                @if ($multipleEngines)
                                    <flux:table.cell>{{ $database->engine->label() }}</flux:table.cell>
                                @endif
                                <flux:table.cell><x-status :color="$database->status->color()" :label="$database->status->label()" /></flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($database->engine === DatabaseEngine::Mysql && ! $database->isSystemDatabase())
                                        @can('transferDatabases', $server)
                                            <flux:tooltip :content="__('Export')">
                                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('servers.databases.export', [$server, $database])" :disabled="! $database->isTransferable()" :aria-label="__('Export :name', ['name' => $database->name])" />
                                            </flux:tooltip>
                                            <flux:tooltip :content="__('Restore from a file')">
                                                <flux:button size="sm" variant="ghost" icon="arrow-up-tray" wire:click="startRestore({{ $database->id }})" :disabled="! $database->isTransferable()" :aria-label="__('Restore :name', ['name' => $database->name])" />
                                            </flux:tooltip>
                                        @endcan
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDatabase({{ $database->id }})" wire:confirm.danger="{{ __('Drop the :name database and all of its data?', ['name' => $database->name]) }}" />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="$multipleEngines ? 4 : 3">{{ __('No databases yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-panel>

            @if ($server->isDatabaseServer())
                <x-panel icon="shield-check" :heading="__('Remote access')" :description="__('Only these addresses can reach the database ports. Connect to :address on port :ports.', ['address' => $server->connectionAddress(), 'ports' => collect($server->databaseEngines())->map(fn ($databaseEngine) => $databaseEngine->port().' ('.$databaseEngine->label().')')->implode(', ')])" flush>
                    <x-slot:actions>
                        <form wire:submit="allowAccess" class="flex flex-col items-end gap-1">
                            <div class="flex items-center gap-2">
                                @if ($this->accessCandidates->isNotEmpty())
                                    <flux:dropdown>
                                        <flux:button size="sm" icon:trailing="chevron-down">{{ __('Allow a server') }}</flux:button>
                                        <flux:menu>
                                            @foreach ($this->accessCandidates as $candidate)
                                                <flux:menu.item wire:click="allowServer({{ $candidate['server']->id }})" :disabled="$candidate['address'] === null || in_array($candidate['address'], $server->databaseAccess(), true)">
                                                    {{ $candidate['server']->name }}
                                                    <span class="ms-2 font-mono text-xs text-zinc-500">{{ $candidate['address'] ?? __('no private IP') }}</span>
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                                <flux:input wire:model="accessAddress" size="sm" :aria-label="__('IP address or range')" placeholder="10.0.0.2" class="w-40 font-mono" />
                                <flux:button type="submit" size="sm" variant="primary" icon="plus">{{ __('Allow') }}</flux:button>
                            </div>
                            <flux:error name="accessAddress" />
                        </form>
                    </x-slot:actions>

                    <flux:table>
                        <flux:table.rows>
                            @forelse ($server->databaseAccess() as $address)
                                <flux:table.row :key="'access-'.$address">
                                    <flux:table.cell class="font-mono">{{ $address }}</flux:table.cell>
                                    <flux:table.cell>{{ $this->accessCandidates->firstWhere('address', $address)['server']->name ?? '' }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="revokeAccess('{{ $address }}')" wire:confirm.danger="{{ __('Stop :address from connecting to the databases?', ['address' => $address]) }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3">{{ $server->private_ip_address
                                        ? __('No addresses allowed yet. App servers connect over the private network, from their private IP address.')
                                        : __('No addresses allowed yet. Nothing outside this server can connect.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </x-panel>
            @endif

            <x-panel icon="users" :heading="__('Database users')" flush>
                <x-slot:actions>
                    <flux:button size="sm" icon="plus" wire:click="newUser">{{ __('New user') }}</flux:button>
                </x-slot:actions>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Username') }}</flux:table.column>
                        @if ($multipleEngines)
                            <flux:table.column>{{ __('Engine') }}</flux:table.column>
                        @endif
                        <flux:table.column>{{ __('Databases') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->users as $user)
                            <flux:table.row :key="'user-'.$user->id">
                                <flux:table.cell class="font-mono">{{ $user->username }}</flux:table.cell>
                                @if ($multipleEngines)
                                    <flux:table.cell>{{ $user->engine->label() }}</flux:table.cell>
                                @endif
                                <flux:table.cell>{{ $user->databases->pluck('name')->implode(', ') ?: '—' }}</flux:table.cell>
                                <flux:table.cell><x-status :color="$user->status->color()" :label="$user->status->label()" /></flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editUser({{ $user->id }})" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteUser({{ $user->id }})" wire:confirm.danger="{{ __('Delete the :name user?', ['name' => $user->username]) }}" />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="$multipleEngines ? 5 : 4">{{ __('No database users yet. Sites can also use the ":user" account.', ['user' => config('swage.user')]) }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-panel>
        </div>

        <flux:modal name="restore-database" class="w-full max-w-md">
            @if ($database = $this->restoringDatabase)
                {{-- Not a form, so pressing enter cannot start a restore without the second confirmation. --}}
                <div class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg">{{ __('Restore :name', ['name' => $database->name]) }}</flux:heading>
                        <flux:subheading class="mt-2">{{ __('Every table in :name is dropped, then the file is imported in their place. Export the database first if you may want its data back.', ['name' => $database->name]) }}</flux:subheading>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Dump file') }}</flux:label>
                        <flux:description>{{ __('A .sql or .sql.gz file of this one database, up to 512 MB, such as an export from here or from mysqldump.') }}</flux:description>
                        <flux:input type="file" wire:model="restoreFile" accept=".sql,.gz" />
                        <flux:text wire:loading wire:target="restoreFile" class="text-sm">{{ __('Uploading…') }}</flux:text>
                        <flux:error name="restoreFile" />
                    </flux:field>

                    <flux:input wire:model="restoreConfirmation" :label="__('Type :name to confirm', ['name' => $database->name])" class="font-mono" autocomplete="off" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="restoreDatabase" wire:loading.attr="disabled" wire:target="restoreFile" wire:confirm.danger="{{ __('Replace everything in :name with this file? The current tables and data are deleted and cannot be recovered.', ['name' => $database->name]) }}">{{ __('Restore') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>

        <flux:modal name="database-user" class="w-full max-w-md">
            <form wire:submit="saveUser" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ $editingUserId ? __('Edit database user') : __('New database user') }}</flux:heading>

                @if ($multipleEngines)
                    <flux:select wire:model.live="userEngine" :label="__('Engine')" :disabled="(bool) $editingUserId">
                        @foreach ($server->databaseEngines() as $databaseEngine)
                            <flux:select.option :value="$databaseEngine->value">{{ $databaseEngine->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="username" :label="__('Username')" :disabled="(bool) $editingUserId" required />
                <flux:input wire:model="password" :label="__('Password')" :placeholder="$editingUserId ? __('Leave blank to keep the current password') : ''" copyable />

                <flux:checkbox.group wire:model="userDatabases" :label="__('Access to')">
                    @foreach ($this->databases->where('engine', DatabaseEngine::tryFrom($userEngine)) as $database)
                        <flux:checkbox :value="$database->id" :label="$database->name" wire:key="user-db-{{ $database->id }}" />
                    @endforeach
                </flux:checkbox.group>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::servers.layout>
</div>
