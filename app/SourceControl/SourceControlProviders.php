<?php

namespace App\SourceControl;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use ReflectionClass;

/**
 * The providers sites can install repositories from. Built-in providers are registered by Swage, and plugins register
 * theirs from a service provider's boot method:
 *
 *     app(SourceControlProviders::class)->register(GiteaProvider::class);
 */
final class SourceControlProviders
{
    /**
     * The version of the SourceControlProvider and SourceControlClient contracts plugins are written against.
     */
    public const int CONTRACT_VERSION = 1;

    /**
     * The id of repositories installed from a clone URL, with a deploy key added by hand. No provider may use it.
     */
    public const string CUSTOM = 'custom';

    /**
     * @var array<string, SourceControlProvider>
     */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register a provider, by instance or by a class the container resolves.
     *
     * @param  SourceControlProvider|class-string<SourceControlProvider>  $provider
     */
    public function register(SourceControlProvider|string $provider): void
    {
        $instance = is_string($provider) ? $this->container->make($provider) : $provider;

        if (! $instance instanceof SourceControlProvider) {
            throw new InvalidArgumentException(get_debug_type($instance).' does not implement '.SourceControlProvider::class.'.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $instance->id()) || $instance->id() === self::CUSTOM) {
            throw new InvalidArgumentException("[{$instance->id()}] is not a valid source control provider id.");
        }

        $this->providers[$instance->id()] = $instance;
    }

    /**
     * @return array<string, SourceControlProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Get the providers a plugin's package registered, found by where their classes are installed.
     *
     * @return array<string, SourceControlProvider>
     */
    public function providedBy(string $package): array
    {
        return array_filter($this->providers, function (SourceControlProvider $provider) use ($package): bool {
            $file = (new ReflectionClass($provider))->getFileName();

            return $file !== false && str_contains(str_replace('\\', '/', $file), "/vendor/{$package}/");
        });
    }

    public function find(string $id): ?SourceControlProvider
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * @throws InvalidArgumentException when no provider has the id, such as when its plugin was removed.
     */
    public function get(string $id): SourceControlProvider
    {
        return $this->find($id) ?? throw new InvalidArgumentException("No source control provider [{$id}] is installed.");
    }

    /**
     * Create a client acting as the user's connected account on a provider.
     *
     * @throws NotConnectedException
     */
    public function clientFor(User $user, string $providerId): SourceControlClient
    {
        $provider = $this->get($providerId);
        $connection = $user->sourceControlConnection($providerId);

        if ($connection === null) {
            throw new NotConnectedException($user, $provider);
        }

        return $provider->client($connection);
    }

    /**
     * Get the providers a user has connected an account to.
     *
     * @return array<string, SourceControlProvider>
     */
    public function connectedFor(User $user): array
    {
        $connected = $user->sourceControlConnections()->pluck('provider')->all();

        return array_filter($this->all(), fn (SourceControlProvider $provider): bool => in_array($provider->id(), $connected, true));
    }
}
