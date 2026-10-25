<?php

namespace App\Actions\Sites;

use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\Ssh\Script;
use LogicException;

class EnablePrivateRedis
{
    public function __construct(protected UpdateSiteEnvironment $updateEnvironment) {}

    /**
     * Start a Redis server only the site's user can reach and point the site's .env at it.
     *
     * Whatever the site kept in the shared Redis server, such as cache, sessions and queued jobs, stays behind.
     *
     * @throws ScriptFailedException
     */
    public function handle(Site $site): void
    {
        if (! $site->supportsPrivateRedis()) {
            throw new LogicException('Only isolated sites on a server with Redis can have a private Redis server.');
        }

        $site->private_redis = true;

        try {
            $this->updateEnvironment->handle($site, [
                'REDIS_HOST' => $site->redisSocket(),
                'REDIS_PORT' => '0',
                'REDIS_USERNAME' => null,
                'REDIS_PASSWORD' => null,
            ], "Moving {$site->domain} to a private Redis server", Script::render('sites.private-redis', ['site' => $site]));
        } catch (ScriptFailedException $exception) {
            $site->private_redis = false;

            throw $exception;
        }

        $site->save();
    }
}
