<?php

namespace App\Livewire\Concerns;

use App\Enums\MemberRole;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Adds people to a server or site by the email address of their Swage account, and changes or removes their role.
 */
trait ManagesMembers
{
    public string $email = '';

    public string $role = 'viewer';

    /**
     * The server or site whose members are managed.
     */
    abstract protected function membersOf(): Server|Site;

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->membersOf()->members()->orderBy('name')->get();
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->membersOf());

        $this->email = trim($this->email);

        $this->validate([
            'email' => ['required', 'email', Rule::exists(User::class, 'email')],
            'role' => ['required', Rule::enum(MemberRole::class)],
        ], [
            'email.exists' => __('No one has a Swage account with this email address. An administrator can add them on the Users page.'),
        ]);

        $user = User::firstWhere('email', $this->email);

        if ($user->is($this->owner())) {
            throw ValidationException::withMessages(['email' => __(':name owns the server, so already has access.', ['name' => $user->name])]);
        }

        if ($this->membersOf()->members()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => __(':name is already a member. Change their role below instead.', ['name' => $user->name])]);
        }

        $this->membersOf()->members()->attach($user->id, ['role' => $this->role]);

        $this->reset('email', 'role');
        unset($this->members);

        Flux::toast(variant: 'success', text: __(':name added.', ['name' => $user->name]));
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('manageMembers', $this->membersOf());

        $role = MemberRole::tryFrom($role) ?? abort(422);

        $this->membersOf()->members()->updateExistingPivot($this->member($userId)->id, ['role' => $role->value]);
        unset($this->members);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manageMembers', $this->membersOf());

        $user = $this->member($userId);

        $this->membersOf()->members()->detach($user->id);
        unset($this->members);

        Flux::toast(variant: 'success', text: __(':name removed.', ['name' => $user->name]));
    }

    protected function member(int $userId): User
    {
        return $this->membersOf()->members()->whereKey($userId)->first() ?? abort(404);
    }

    protected function owner(): User
    {
        $membersOf = $this->membersOf();

        return $membersOf instanceof Site ? $membersOf->server->user : $membersOf->user;
    }
}
