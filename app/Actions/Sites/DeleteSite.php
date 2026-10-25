<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\RemoveSite;
use App\Models\Site;

class DeleteSite
{
    /**
     * Remove the site from its server in the background. Its databases are kept.
     */
    public function handle(Site $site): void
    {
        $site->update(['status' => ResourceStatus::Removing]);

        RemoveSite::dispatch($site);
    }
}
