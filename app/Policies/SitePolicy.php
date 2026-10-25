<?php

namespace App\Policies;

use App\Enums\MemberRole;
use App\Models\Site;
use App\Models\User;

/**
 * Whoever may view or manage a server may do the same with its sites. A site's own members reach only that site.
 */
class SitePolicy
{
    public function view(User $user, Site $site): bool
    {
        return $user->can('view', $site->server) || $site->memberRole($user) !== null;
    }

    /**
     * Determine whether the user can manage the site, including reading its environment, which holds its secrets.
     */
    public function update(User $user, Site $site): bool
    {
        return $user->can('update', $site->server) || $site->memberRole($user) === MemberRole::Manager;
    }

    /**
     * Determine whether the user can delete the site, which only those managing its server can.
     */
    public function delete(User $user, Site $site): bool
    {
        return $user->can('update', $site->server);
    }

    /**
     * Determine whether the user can act on the site's repository through a source control account: installing a
     * repository from a provider or turning quick deploy on and off. Swage acts through the server owner's connected
     * account, so only the owner may, never members who would otherwise reach the owner's repositories.
     */
    public function useSourceControl(User $user, Site $site): bool
    {
        return $site->server->user_id === $user->id;
    }

    public function manageMembers(User $user, Site $site): bool
    {
        return $user->can('manageMembers', $site->server) || $site->memberRole($user) === MemberRole::Manager;
    }
}
