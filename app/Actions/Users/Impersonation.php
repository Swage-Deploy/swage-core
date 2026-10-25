<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;

/**
 * Lets an administrator sign in as another user to see Swage as they do, and sign back in as themselves.
 *
 * The administrator's id is kept in the session, which logging in as the user carries over. Administrators cannot be
 * impersonated, so impersonating never gives anyone more access than an administrator already has. Nor can users who
 * have not verified their email address, who only ever see the page asking them to.
 */
class Impersonation
{
    public const string SESSION_KEY = 'impersonator_id';

    /**
     * Sign the administrator in as the user.
     *
     * @throws InvalidArgumentException when the user cannot be impersonated.
     */
    public function start(User $administrator, User $user): void
    {
        if (! $this->canImpersonate($administrator, $user)) {
            throw new InvalidArgumentException("{$administrator->email} cannot impersonate {$user->email}.");
        }

        Log::notice("{$administrator->email} started impersonating {$user->email}.", ['impersonator_id' => $administrator->id, 'user_id' => $user->id]);

        Auth::guard('web')->login($user);

        Session::put(self::SESSION_KEY, $administrator->id);
    }

    /**
     * Sign the administrator back in as themselves. When they are no longer an administrator, nobody stays signed in.
     */
    public function stop(): ?User
    {
        $impersonator = $this->impersonator();
        $user = Auth::user();

        Session::forget(self::SESSION_KEY);

        if (! $impersonator?->is_admin) {
            Auth::guard('web')->logout();
            Session::invalidate();
            Session::regenerateToken();

            return null;
        }

        Log::notice("{$impersonator->email} stopped impersonating {$user?->email}.", ['impersonator_id' => $impersonator->id, 'user_id' => $user?->id]);

        Auth::guard('web')->login($impersonator);

        return $impersonator;
    }

    public function canImpersonate(User $administrator, User $user): bool
    {
        return $administrator->is_admin
            && ! $user->is_admin
            && ! $user->is($administrator)
            && $user->hasVerifiedEmail()
            && ! $this->isImpersonating();
    }

    public function isImpersonating(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /**
     * The administrator signed in as someone else.
     */
    public function impersonator(): ?User
    {
        $id = Session::get(self::SESSION_KEY);

        return $id ? User::find($id) : null;
    }
}
