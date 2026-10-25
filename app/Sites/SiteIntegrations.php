<?php

namespace App\Sites;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The integrations sites can be connected to. Plugins register theirs from a service provider's boot method:
 *
 *     app(SiteIntegrations::class)->register(LaraOwlIntegration::class);
 */
final class SiteIntegrations
{
    /**
     * @var array<string, SiteIntegration>
     */
    private array $integrations = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register an integration, by instance or by a class the container resolves.
     *
     * @param  SiteIntegration|class-string<SiteIntegration>  $integration
     */
    public function register(SiteIntegration|string $integration): void
    {
        $instance = is_string($integration) ? $this->container->make($integration) : $integration;

        if (! $instance instanceof SiteIntegration) {
            throw new InvalidArgumentException(get_debug_type($instance).' does not implement '.SiteIntegration::class.'.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $instance->id())) {
            throw new InvalidArgumentException("[{$instance->id()}] is not a valid site integration id.");
        }

        $this->integrations[$instance->id()] = $instance;
    }

    /**
     * @return array<string, SiteIntegration>
     */
    public function all(): array
    {
        return $this->integrations;
    }
}
