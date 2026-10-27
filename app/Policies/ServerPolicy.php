<?php

namespace App\Policies;

use App\Enums\MemberRole;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Determine whether the user can view the server and everything on it: its owner, its members, and administrators,
     * who can already sign in as anyone.
     */
    public function view(User $user, Server $server): bool
    {
        return $this->owns($user, $server) || $user->is_admin || $server->memberRole($user) !== null;
    }

    /**
     * Determine whether the user can manage the server and everything on it.
     */
    public function update(User $user, Server $server): bool
    {
        return $this->owns($user, $server) || $server->memberRole($user) === MemberRole::Manager;
    }

    /**
     * Determine whether the user can run daemons and scheduled jobs as root, and change the config files of the server's
     * services, which nginx, PHP-FPM, Redis and the databases read as root. Either controls the whole server, so only its
     * owner may, never its members.
     */
    public function runAsRoot(User $user, Server $server): bool
    {
        return $this->owns($user, $server);
    }

    /**
     * Determine whether the user can delete the server.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->owns($user, $server);
    }

    /**
     * Determine whether the user can add, change and remove the server's members.
     */
    public function manageMembers(User $user, Server $server): bool
    {
        return $this->owns($user, $server) || $user->is_admin || $server->memberRole($user) === MemberRole::Manager;
    }

    /**
     * Determine whether the user can export the server's databases and restore them from a file. Exports hold all of a
     * database's data, so viewers cannot.
     */
    public function transferDatabases(User $user, Server $server): bool
    {
        return $this->owns($user, $server) || $user->is_admin || $server->memberRole($user) === MemberRole::Manager;
    }

    protected function owns(User $user, Server $server): bool
    {
        return $server->user_id === $user->id;
    }
}
