<?php

namespace App\Enums;

/**
 * What an API key may do. Each area has a read ability and a write ability, and a key given write is given read too.
 *
 * A key never does more than the user it belongs to: the server and site policies still decide what it can reach.
 */
enum ApiAbility: string
{
    case ServersRead = 'servers:read';
    case ServersWrite = 'servers:write';
    case SitesRead = 'sites:read';
    case SitesWrite = 'sites:write';
    case DeploymentsRead = 'deployments:read';
    case DeploymentsWrite = 'deployments:write';
    case EnvironmentRead = 'environment:read';
    case EnvironmentWrite = 'environment:write';
    case CertificatesRead = 'certificates:read';
    case CertificatesWrite = 'certificates:write';
    case CommandsRead = 'commands:read';
    case CommandsWrite = 'commands:write';
    case DaemonsRead = 'daemons:read';
    case DaemonsWrite = 'daemons:write';
    case DatabasesRead = 'databases:read';
    case DatabasesWrite = 'databases:write';

    /**
     * The areas a key can be given access to, each with what reading and writing it allows.
     *
     * @return array<string, array{label: string, read: string, write: string}>
     */
    public static function areas(): array
    {
        return [
            'servers' => ['label' => __('Servers'), 'read' => __('List servers and their activity.'), 'write' => __('Connect, reboot, re-optimize and delete servers.')],
            'sites' => ['label' => __('Sites'), 'read' => __('List sites and their settings.'), 'write' => __('Create, change and delete sites, and install their repositories.')],
            'deployments' => ['label' => __('Deployments'), 'read' => __('List deployments, their output and deploy scripts.'), 'write' => __('Deploy, change deploy scripts and turn quick deploy on or off.')],
            'environment' => ['label' => __('Environment'), 'read' => __('Read sites\' .env files, which hold their secrets.'), 'write' => __('Replace sites\' .env files.')],
            'certificates' => ['label' => __('Certificates'), 'read' => __('See sites\' SSL certificates.'), 'write' => __('Obtain and remove certificates.')],
            'commands' => ['label' => __('Commands'), 'read' => __('List Artisan commands run on sites and their output.'), 'write' => __('Run Artisan commands on sites.')],
            'daemons' => ['label' => __('Daemons'), 'read' => __('List daemons, queue workers and scheduled jobs.'), 'write' => __('Create, restart and remove them, and turn sites\' schedulers on or off.')],
            'databases' => ['label' => __('Databases'), 'read' => __('List databases and database users.'), 'write' => __('Create, change and delete them.')],
        ];
    }

    /**
     * The abilities to store for a key given these, adding read wherever write was given.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function normalize(array $abilities): array
    {
        return collect($abilities)
            ->map(fn (string $ability): self => self::from($ability))
            ->flatMap(fn (self $ability): array => str_ends_with($ability->value, ':write')
                ? [self::from(str_replace(':write', ':read', $ability->value)), $ability]
                : [$ability])
            ->map->value
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The abilities a key gets with full access.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
}
