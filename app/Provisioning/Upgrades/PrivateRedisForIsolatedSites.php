<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Actions\Sites\EnablePrivateRedis;
use App\Actions\Sites\UpdateSiteEnvironment;
use App\Enums\ConfigService;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServiceConfigFiles;
use App\Sites\SiteEnvironmentFile;
use App\Ssh\Script;
use Illuminate\Support\Str;

/**
 * Moves isolated sites still using the shared Redis server onto Redis servers of their own, then changes the shared
 * server's password so the one they knew stops working.
 *
 * The shared Redis user can read and write every key, so an isolated site on it could read other sites' sessions and
 * cache, or queue jobs that another site would run. Isolated sites have been given private Redis since they were
 * introduced on new servers; this catches up the ones created before.
 *
 * The password changes without refusing any site: the new password is added beside the old one, every site still on
 * the shared server switches to it, and only then is the old one removed. Run again after a failure, it starts over
 * with another new password, which also reaches every site.
 */
final class PrivateRedisForIsolatedSites implements ServerUpgrade
{
    public const string ID = '2026_10_26_private_redis_for_isolated_sites';

    public function __construct(
        protected RunServerTask $runTask,
        protected EnablePrivateRedis $enablePrivateRedis,
        protected SiteEnvironmentFile $environmentFile,
        protected UpdateSiteEnvironment $updateEnvironment,
    ) {}

    public function title(): string
    {
        return __('Give isolated sites their own Redis');
    }

    public function description(): string
    {
        return __('Isolated sites still on the shared Redis server, where they could read other sites\' sessions and cache, move to a private Redis server, and the shared password is changed. What they kept in the shared server stays behind: cached values, sessions, which signs their visitors out, and queued jobs not yet run, so let their queues finish first.');
    }

    public function apply(Server $server): void
    {
        if (! $server->has_redis) {
            return;
        }

        $sites = $server->sites()->with('server')->get();

        foreach ($sites->filter(fn (Site $site): bool => $site->isIsolated() && ! $site->private_redis) as $site) {
            $this->enablePrivateRedis->handle($site);
        }

        if ($server->usesRedisAuthentication()) {
            $this->changePassword($server, $sites->reject(fn (Site $site): bool => (bool) $site->private_redis));
        }
    }

    /**
     * @param  iterable<Site>  $sharedSites
     */
    protected function changePassword(Server $server, iterable $sharedSites): void
    {
        $current = $server->redis_password;
        $password = Str::password(40, symbols: false);

        $this->runTask->run($server, 'Adding a new Redis password', Script::render('upgrades.redis-password', [
            'current' => $current,
            'password' => $password,
            'files' => null,
        ]), timeout: 60);

        $server->update(['redis_password' => $password]);

        foreach ($sharedSites as $site) {
            $site->setRelation('server', $server);

            $this->updatePassword($site);
        }

        $this->runTask->run($server, 'Removing the old Redis password', Script::render('upgrades.redis-password', [
            'current' => $password,
            'password' => $password,
            'files' => ServiceConfigFiles::for($server)->files(ConfigService::Redis),
        ]), timeout: 60);
    }

    protected function updatePassword(Site $site): void
    {
        $environment = $this->environmentFile->read($site);

        if ($environment === null || ! in_array($environment['REDIS_HOST'] ?? '127.0.0.1', ['127.0.0.1', 'localhost'], true)) {
            return;
        }

        $this->updateEnvironment->handle($site, [
            'REDIS_USERNAME' => config('swage.user'),
            'REDIS_PASSWORD' => $site->server->redis_password,
        ], "Changing the Redis password of {$site->domain}");
    }
}
