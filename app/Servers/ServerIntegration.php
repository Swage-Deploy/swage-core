<?php

namespace App\Servers;

use App\Models\Server;

/**
 * A service servers can be set up with, added by a plugin. Plugins register their implementations with
 * ServerIntegrations.
 *
 * Plugins depend on this contract, so changing it is a breaking change for them: add methods to a new interface
 * instead, and bump SourceControlProviders::CONTRACT_VERSION when an existing method must change.
 */
interface ServerIntegration
{
    /**
     * The short identifier of the integration, such as "netdata". Lowercase letters, numbers and dashes.
     */
    public function id(): string;

    /**
     * The name of the Livewire component shown on a server's Plugins tab. It is given the server as "server".
     */
    public function settingsComponent(): string;

    /**
     * Secrets the integration stores for the server, which must never be stored in task or deployment output.
     *
     * @return list<string|null>
     */
    public function secrets(Server $server): array;
}
