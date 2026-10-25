<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Provisioning\Upgrades\AutomaticSecurityUpdates;
use App\Provisioning\Upgrades\CloudflareVisitorAddresses;
use App\Provisioning\Upgrades\RedisAuthentication;
use App\Provisioning\Upgrades\RestrictSudo;
use App\Provisioning\Upgrades\RootOwnedDaemonLogs;
use App\Provisioning\Upgrades\SeparateNginxUser;
use App\Provisioning\Upgrades\ServerUpgrade;
use App\Provisioning\Upgrades\SiteDatabaseUsers;

/**
 * The upgrades existing servers need to match what Swage provisions today.
 */
final class ServerUpgrades
{
    /**
     * Every upgrade by id, in the order they run. Ids are stored on servers, so never change or reuse one.
     *
     * @var array<string, class-string<ServerUpgrade>>
     */
    public const array UPGRADES = [
        RestrictSudo::ID => RestrictSudo::class,
        SeparateNginxUser::ID => SeparateNginxUser::class,
        SiteDatabaseUsers::ID => SiteDatabaseUsers::class,
        RedisAuthentication::ID => RedisAuthentication::class,
        RootOwnedDaemonLogs::ID => RootOwnedDaemonLogs::class,
        AutomaticSecurityUpdates::ID => AutomaticSecurityUpdates::class,
        CloudflareVisitorAddresses::ID => CloudflareVisitorAddresses::class,
    ];

    /**
     * Get the ids of every upgrade.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::UPGRADES);
    }

    /**
     * Get the upgrades a server has not had yet.
     *
     * @return array<string, ServerUpgrade>
     */
    public static function pendingFor(Server $server): array
    {
        return collect(self::UPGRADES)
            ->reject(fn (string $upgrade, string $id): bool => $server->hasAppliedUpgrade($id))
            ->map(fn (string $upgrade): ServerUpgrade => app($upgrade))
            ->all();
    }
}
