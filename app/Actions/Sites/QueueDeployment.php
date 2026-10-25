<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\DeploySite;
use App\Models\Deployment;
use App\Models\Site;

class QueueDeployment
{
    /**
     * Queue a new deployment of the site.
     */
    public function handle(Site $site, string $triggeredBy): Deployment
    {
        $deployment = $site->deployments()->create([
            'status' => DeploymentStatus::Pending,
            'triggered_by' => $triggeredBy,
        ]);

        DeploySite::dispatch($deployment);

        return $deployment;
    }
}
