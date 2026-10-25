<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\InstallScheduledJob;
use App\Jobs\RemoveScheduledJob;
use App\Models\ScheduledJob;
use App\Models\Site;
use App\Sites\SiteCommands;

class SetScheduler
{
    /**
     * The scheduled job that runs the site's Laravel scheduler, if it has one.
     */
    public static function current(Site $site): ?ScheduledJob
    {
        return $site->scheduledJobs()->where('command', SiteCommands::scheduler($site))->first();
    }

    /**
     * Turn the site's Laravel scheduler on or off.
     */
    public function handle(Site $site, bool $enabled): void
    {
        $scheduler = self::current($site);

        if (! $enabled && $scheduler) {
            $scheduler->update(['status' => ResourceStatus::Removing]);
            RemoveScheduledJob::dispatch($scheduler);
        }

        if ($enabled && ! $scheduler) {
            InstallScheduledJob::dispatch($site->server->scheduledJobs()->create([
                'site_id' => $site->id,
                'command' => SiteCommands::scheduler($site),
                'user' => $site->systemUser(),
                'frequency' => '* * * * *',
                'status' => ResourceStatus::Pending,
            ]));
        }
    }
}
