<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Actions\Sites\UpdateSiteEnvironment;
use App\Enums\ConfigService;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServiceConfigFiles;
use App\Sites\SiteEnvironmentFile;
use App\Ssh\Script;
use Illuminate\Support\Str;

/**
 * Makes the shared Redis server require a password, which Swage writes into the .env of every site using it.
 *
 * The steps are ordered so no site is ever refused: the password user is added while anonymous access still works,
 * every site switches to it, and only then is anonymous access turned off.
 */
final class RedisAuthentication implements ServerUpgrade
{
    public const string ID = '2026_10_16_redis_authentication';

    public function __construct(
        protected RunServerTask $runTask,
        protected SiteEnvironmentFile $environmentFile,
        protected UpdateSiteEnvironment $updateEnvironment,
    ) {}

    public function title(): string
    {
        return __('Require a password for Redis');
    }

    public function description(): string
    {
        return __('The shared Redis server stops accepting anonymous connections, and each site using it gets the password in its .env. Isolated sites still on the shared server can then be moved to a private Redis server from their settings.');
    }

    public function apply(Server $server): void
    {
        if (! $server->has_redis) {
            return;
        }

        if (blank($server->redis_password)) {
            $server->update(['redis_password' => Str::password(40, symbols: false)]);
        }

        $this->runTask->run($server, 'Adding the Redis password user', Script::render('upgrades.redis-user', [
            'password' => $server->redis_password,
        ]), timeout: 60);

        foreach ($server->sites()->with('server')->get() as $site) {
            $this->addPassword($site);
        }

        $this->disableAnonymousAccess($server);
    }

    protected function addPassword(Site $site): void
    {
        if ($site->private_redis) {
            return;
        }

        $environment = $this->environmentFile->read($site);

        if ($environment === null
            || ! in_array($environment['REDIS_HOST'] ?? '127.0.0.1', ['127.0.0.1', 'localhost'], true)
            || ! in_array($environment['REDIS_PASSWORD'] ?? null, [null, '', 'null'], true)) {
            return;
        }

        $this->updateEnvironment->handle($site, [
            'REDIS_USERNAME' => config('swage.user'),
            'REDIS_PASSWORD' => $site->server->redis_password,
        ], "Adding the Redis password to {$site->domain}");
    }

    /**
     * Write the Redis config with the password user and anonymous access off, then turn it off on the running server.
     */
    protected function disableAnonymousAccess(Server $server): void
    {
        $previous = $server->applied_upgrades;
        $server->applied_upgrades = [...($previous ?? []), self::ID];

        try {
            $this->runTask->run($server, 'Turning off anonymous Redis access', Script::render('upgrades.redis-lockdown', [
                'files' => ServiceConfigFiles::for($server)->files(ConfigService::Redis),
            ]), timeout: 60);
        } finally {
            $server->applied_upgrades = $previous;
        }
    }
}
