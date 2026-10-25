<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Site;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UpdateSiteConfiguration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {
        $certificate = $this->site->certificate?->isActive() ? $this->site->certificate : null;

        $runTask->run($this->site->server, "Updating nginx for {$this->site->domain}", Script::render('sites.update-nginx', [
            'site' => $this->site,
            'certificate' => $certificate,
        ]));

        $this->site->update(['status' => ResourceStatus::Installed]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->update(['status' => ResourceStatus::Failed]);
    }
}
