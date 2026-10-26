<?php

namespace App\Provisioning\Upgrades;

use App\Enums\DatabaseEngine;
use App\Jobs\SyncDatabaseUser;
use App\Models\Server;
use Illuminate\Support\Facades\Bus;

/**
 * Grants every MySQL database user its databases again, with the names escaped.
 *
 * In a MySQL grant, _ matches any character, so a user given shop_db could also reach a database named shop1db or
 * shopxdb. Syncing a user revokes its grants before giving them again, so the old patterns are removed.
 */
final class ExactDatabaseGrants implements ServerUpgrade
{
    public const string ID = '2026_10_26_exact_database_grants';

    public function title(): string
    {
        return __('Limit database users to exactly their databases');
    }

    public function description(): string
    {
        return __('MySQL treats _ in a granted database name as any character, so a user given shop_db could also reach a database named shop1db. Each MySQL user is granted its databases again with the names matched exactly. Their passwords and databases stay the same.');
    }

    public function apply(Server $server): void
    {
        foreach ($server->databaseUsers()->where('engine', DatabaseEngine::Mysql)->with('server', 'databases')->get() as $databaseUser) {
            Bus::dispatchSync(new SyncDatabaseUser($databaseUser));
        }
    }
}
