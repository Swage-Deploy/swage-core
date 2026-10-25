<?php

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Jobs\RevokeServerAccess;
use App\Jobs\RevokeSourceControlAccess;
use App\Models\Server;

class RemoveServer
{
    /**
     * Stop managing a server: remove Swage's SSH key from it and its sites' deploy keys and webhooks from their providers,
     * then delete its record. The machine and everything on it keep running.
     *
     * Both jobs carry what they need, because the server, its sites and even its owner are deleted before they run.
     */
    public function handle(Server $server): void
    {
        $revokeSourceControlAccess = RevokeSourceControlAccess::forSites($server->user, $server->sites()->whereNotNull('repository')->get());

        if ($server->status !== ServerStatus::Pending) {
            dispatch(RevokeServerAccess::for($server));
        }

        $server->delete();

        dispatch($revokeSourceControlAccess);
    }
}
