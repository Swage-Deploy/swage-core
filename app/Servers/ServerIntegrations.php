<?php

namespace App\Servers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The integrations servers can be set up with. Plugins register theirs from a service provider's boot method:
 *
 *     app(ServerIntegrations::class)->register(NetdataIntegration::class);
 */
final class ServerIntegrations
{
    /**
     * @var array<string, ServerIntegration>
     */
    private array $integrations = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register an integration, by instance or by a class the container resolves.
     *
     * @param  ServerIntegration|class-string<ServerIntegration>  $integration
     */
    public function register(ServerIntegration|string $integration): void
    {
        $instance = is_string($integration) ? $this->container->make($integration) : $integration;

        if (! $instance instanceof ServerIntegration) {
            throw new InvalidArgumentException(get_debug_type($instance).' does not implement '.ServerIntegration::class.'.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $instance->id())) {
            throw new InvalidArgumentException("[{$instance->id()}] is not a valid server integration id.");
        }

        $this->integrations[$instance->id()] = $instance;
    }

    /**
     * @return array<string, ServerIntegration>
     */
    public function all(): array
    {
        return $this->integrations;
    }
}
