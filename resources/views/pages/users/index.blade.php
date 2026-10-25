<?php

use App\Actions\Users\Impersonation;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public bool $is_admin = false;

    /**
     * The user being edited, or null when adding one.
     */
    #[Locked]
    public ?int $editingId = null;

    /**
     * A password just generated for a user, shown once and then forgotten.
     */
    #[Locked]
    public ?string $generatedPassword = null;

    #[Locked]
    public ?string $generatedFor = null;

    public function mount(): void
    {
        $this->authorize('administer');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::withCount('servers')->orderBy('name')->get();
    }

    /**
     * Open the form to add a user.
     */
    public function add(): void
    {
        $this->authorize('administer');

        $this->reset('name', 'email', 'is_admin', 'editingId');
        $this->resetValidation();

        Flux::modal('user-form')->show();
    }

    /**
     * Open the form to change a user's name, email address and whether they are an administrator.
     */
    public function edit(int $userId): void
    {
        $user = $this->otherUser($userId);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->is_admin = (bool) $user->is_admin;
        $this->resetValidation();

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $this->editingId === null ? $this->create() : $this->update();
    }

    /**
     * Create an account with a generated password, which is shown once. The administrator vouches for the email
     * address, so it is marked verified.
     */
    protected function create(): void
    {
        $this->authorize('administer');

        $validated = $this->validate([...$this->profileRules(), 'is_admin' => ['boolean']]);
        $password = Str::password(20);

        $user = User::create([...$validated, 'password' => $password]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->reset('name', 'email', 'is_admin');
        unset($this->users);

        Flux::modal('user-form')->close();
        $this->showPassword($user, $password);
    }

    /**
     * Save changes to a user. The administrator vouches for a new email address, so it stays verified.
     */
    protected function update(): void
    {
        $user = $this->otherUser($this->editingId);

        $validated = $this->validate([...$this->profileRules($user->id), 'is_admin' => ['boolean']]);

        $user->forceFill([...$validated, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        $this->reset('name', 'email', 'is_admin', 'editingId');
        unset($this->users);

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: __(':name saved.', ['name' => $user->name]));
    }

    /**
     * Give a user a new generated password, shown once, and sign them out of devices they asked to be remembered on.
     */
    public function resetPassword(int $userId): void
    {
        $user = $this->otherUser($userId);
        $password = Str::password(20);

        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $this->showPassword($user, $password);
    }

    /**
     * Turn off a user's two-factor authentication, such as after they lost their device. When two-factor
     * authentication is required, they set it up again when they next sign in.
     */
    public function resetTwoFactor(int $userId): void
    {
        $user = $this->otherUser($userId);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        unset($this->users);

        Flux::toast(variant: 'success', text: __('Two-factor authentication reset for :name.', ['name' => $user->name]));
    }

    /**
     * Remove a user. Users who still own servers are kept, since removing them would delete their servers' records.
     */
    public function remove(int $userId): void
    {
        $user = $this->otherUser($userId);

        if ($user->servers()->exists()) {
            Flux::toast(variant: 'danger', text: __(':name still owns servers. Remove their servers first.', ['name' => $user->name]));

            return;
        }

        $user->delete();
        unset($this->users);

        Flux::toast(variant: 'success', text: __(':name removed.', ['name' => $user->name]));
    }

    /**
     * Sign in as a user to see Swage as they do, until stopping from the banner.
     */
    public function impersonate(int $userId, Impersonation $impersonation): void
    {
        $user = $this->otherUser($userId);

        abort_unless($impersonation->canImpersonate(Auth::user(), $user), 403);

        $impersonation->start(Auth::user(), $user);

        $this->redirectRoute('dashboard');
    }

    public function forgetPassword(): void
    {
        $this->generatedPassword = null;
        $this->generatedFor = null;
    }

    /**
     * Find a user other than the one signed in, who manages their own account from their settings.
     */
    protected function otherUser(int $userId): User
    {
        $this->authorize('administer');

        abort_if($userId === Auth::id(), 403);

        return User::findOrFail($userId);
    }

    protected function showPassword(User $user, string $password): void
    {
        $this->generatedPassword = $password;
        $this->generatedFor = $user->email;

        Flux::modal('generated-password')->show();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('Everyone who can sign in to Swage. Manage your own account from your settings.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="add">{{ __('Add user') }}</flux:button>
    </div>

    <x-panel icon="users" :heading="__('Users')" flush>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Two-factor') }}</flux:table.column>
                <flux:table.column>{{ __('Servers') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="'user-'.$user->id">
                        <flux:table.cell class="font-medium">
                            {{ $user->name }}
                            @if ($user->is_admin)
                                <flux:badge size="sm" color="orange" class="ms-2">{{ __('Admin') }}</flux:badge>
                            @endif
                            @if ($user->id === auth()->id())
                                <flux:badge size="sm" color="zinc" class="ms-1">{{ __('You') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user->two_factor_confirmed_at)
                                <x-status color="green" :label="__('On')" />
                            @else
                                <x-status :label="__('Off')" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->servers_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($user->id !== auth()->id())
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Manage :name', ['name' => $user->name])" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:menu.item>
                                        @if (app(Impersonation::class)->canImpersonate(auth()->user(), $user))
                                            <flux:menu.item icon="user-circle" wire:click="impersonate({{ $user->id }})">{{ __('Sign in as :name', ['name' => $user->name]) }}</flux:menu.item>
                                        @endif
                                        <flux:menu.item icon="key" wire:click="resetPassword({{ $user->id }})"
                                            wire:confirm="{{ __('Give :name a new password? Their current password stops working.', ['name' => $user->name]) }}">{{ __('Reset password') }}</flux:menu.item>
                                        <flux:menu.item icon="shield-exclamation" wire:click="resetTwoFactor({{ $user->id }})" :disabled="! $user->two_factor_secret"
                                            wire:confirm="{{ __('Turn off two-factor authentication for :name? They set it up again when they next sign in.', ['name' => $user->name]) }}">{{ __('Reset two-factor') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="remove({{ $user->id }})"
                                            wire:confirm.danger="{{ __('Remove :name? They can no longer sign in.', ['name' => $user->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-panel>

    <flux:modal name="user-form" class="md:w-[28rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit user') : __('Add user') }}</flux:heading>
                @unless ($editingId)
                    <flux:text class="mt-2">{{ __('Swage generates their password and shows it once, for you to pass on.') }}</flux:text>
                @endunless
            </div>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />
            <flux:checkbox wire:model="is_admin" :label="__('Administrator')" :description="__('Can manage updates, plugins and users.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save') : __('Add user') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="generated-password" class="md:w-[28rem]" wire:close="forgetPassword">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('New password') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Pass this to :email. It is not shown again.', ['email' => $generatedFor]) }}</flux:text>
            </div>

            <flux:input readonly copyable :value="$generatedPassword" class="font-mono" />

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
