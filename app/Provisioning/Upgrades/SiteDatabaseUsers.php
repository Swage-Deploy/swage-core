<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Actions\Sites\UpdateSiteEnvironment;
use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Jobs\SyncDatabaseUser;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteEnvironmentFile;
use App\Sites\SiteSystemUser;
use App\Ssh\Script;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gives every site that connects to MySQL with Swage's server-wide account a user of its own, limited to its
 * database, then changes the server-wide account's password so the old one stops working.
 */
final class SiteDatabaseUsers implements ServerUpgrade
{
    public const string ID = '2026_10_16_site_database_users';

    public function __construct(
        protected RunServerTask $runTask,
        protected SiteEnvironmentFile $environmentFile,
        protected UpdateSiteEnvironment $updateEnvironment,
    ) {}

    public function title(): string
    {
        return __('Give each site its own MySQL user');
    }

    public function description(): string
    {
        return __('Sites using Swage\'s server-wide MySQL account, which can reach every database, move to a user limited to the database in their .env. The server-wide password is then changed, so anything else still using it, such as an app reading a second database, has to be given its own user.');
    }

    public function apply(Server $server): void
    {
        // Servers with PostgreSQL were never set up with sites on the server-wide account.
        if (! $server->usesMysql()) {
            return;
        }

        foreach ($server->sites()->with('server')->get() as $site) {
            $this->moveToOwnUser($site);
        }

        $password = Str::password(32, symbols: false);

        $this->runTask->run($server, 'Changing the server-wide MySQL password', Script::render('upgrades.rotate-mysql-password', [
            'password' => $password,
        ]), timeout: 60);

        $server->update(['database_password' => $password]);
    }

    protected function moveToOwnUser(Site $site): void
    {
        $environment = $this->environmentFile->read($site);

        if ($environment === null
            || ($environment['DB_USERNAME'] ?? null) !== config('swage.user')
            || ! in_array($environment['DB_HOST'] ?? '127.0.0.1', ['127.0.0.1', 'localhost'], true)) {
            return;
        }

        $databaseName = (string) ($environment['DB_DATABASE'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/D', $databaseName)) {
            throw new RuntimeException("{$site->domain} uses Swage's server-wide MySQL account, but its DB_DATABASE [{$databaseName}] is not a database name Swage can create a user for. Give the site its own database user, then run the upgrade again.");
        }

        $database = $site->server->databases()->firstOrCreate(['engine' => DatabaseEngine::Mysql, 'name' => $databaseName], ['status' => ResourceStatus::Pending]);

        if ($database->status !== ResourceStatus::Installed) {
            Bus::dispatchSync(new CreateDatabase($database));
        }

        $databaseUser = $this->databaseUserFor($site);
        $databaseUser->databases()->syncWithoutDetaching([$database->id]);

        Bus::dispatchSync(new SyncDatabaseUser($databaseUser));

        $this->updateEnvironment->handle($site, [
            'DB_USERNAME' => $databaseUser->username,
            'DB_PASSWORD' => $databaseUser->password,
        ], "Moving {$site->domain} to its own MySQL user");
    }

    /**
     * Reuse the database user an isolated site was created with, or create one named after the site.
     */
    protected function databaseUserFor(Site $site): DatabaseUser
    {
        $server = $site->server;

        if ($site->isIsolated() && $existing = $server->databaseUsers()->where('engine', DatabaseEngine::Mysql)->where('username', $site->system_user)->first()) {
            return $existing;
        }

        return $server->databaseUsers()->create([
            'engine' => DatabaseEngine::Mysql,
            'username' => $site->isIsolated() ? $site->system_user : SiteSystemUser::suggest($site->domain, $server),
            'password' => Str::random(40),
            'status' => ResourceStatus::Pending,
        ]);
    }
}
