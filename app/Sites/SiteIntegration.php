<?php

namespace App\Sites;

use App\Models\Server;
use App\Models\Site;

/**
 * A service sites can be connected to, such as a monitoring server, added by a plugin. Plugins register their
 * implementations with SiteIntegrations.
 *
 * An integration can also add steps to deploy scripts by implementing HooksIntoDeployments.
 *
 * Plugins depend on this contract, so changing it is a breaking change for them: add methods to a new interface
 * instead, and bump SourceControlProviders::CONTRACT_VERSION when an existing method must change.
 */
interface SiteIntegration
{
    /**
     * The short identifier of the integration, such as "laraowl". Lowercase letters, numbers and dashes.
     */
    public function id(): string;

    /**
     * The name of the Livewire component shown on a site's Plugins tab. It is given the site as "site".
     */
    public function settingsComponent(): string;

    /**
     * Secrets the integration stores for the server's sites, which must never be stored in task or deployment output.
     *
     * @return list<string|null>
     */
    public function secrets(Server $server): array;

    /**
     * Called once a deployment of the site has finished, whether or not it succeeded.
     */
    public function deployed(Site $site): void;
}
