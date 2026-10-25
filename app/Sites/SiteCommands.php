<?php

namespace App\Sites;

use App\Models\Site;

class SiteCommands
{
    /**
     * The Supervisor command for a Laravel queue worker.
     *
     * @param  array{connection: string, queue: string, sleep: int, tries: int, timeout: int}  $options
     */
    public static function queueWorker(Site $site, array $options): string
    {
        return sprintf(
            '%s %s/artisan queue:work %s --queue=%s --sleep=%d --tries=%d --timeout=%d --max-time=3600',
            $site->php_version->binary(),
            $site->currentPath(),
            $options['connection'],
            $options['queue'],
            $options['sleep'],
            $options['tries'],
            $options['timeout'],
        );
    }

    /**
     * The cron command that runs the Laravel scheduler.
     */
    public static function scheduler(Site $site): string
    {
        return "{$site->php_version->binary()} {$site->currentPath()}/artisan schedule:run";
    }

    /**
     * The Supervisor command that serves the site with Octane on FrankenPHP.
     */
    public static function octane(Site $site, int $workers): string
    {
        return sprintf(
            '%s %s/artisan octane:start --server=frankenphp --host=127.0.0.1 --port=%d --workers=%d --max-requests=500 --no-interaction',
            $site->php_version->binary(),
            $site->currentPath(),
            $site->octane_port,
            $workers,
        );
    }
}
