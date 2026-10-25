<?php

namespace App\Provisioning\Upgrades;

use App\Models\Server;

/**
 * A change to servers that were provisioned before Swage started setting them up differently.
 *
 * Newly provisioned servers are already in the upgraded state, so every upgrade only runs on existing servers.
 * Upgrades must be safe to run again if an earlier attempt stopped part way through.
 */
interface ServerUpgrade
{
    /**
     * A short name for the change.
     */
    public function title(): string;

    /**
     * What the upgrade changes on the server, including anything the user should know before running it.
     */
    public function description(): string;

    /**
     * Apply the upgrade to the server.
     */
    public function apply(Server $server): void;
}
