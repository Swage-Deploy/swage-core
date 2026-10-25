<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Site;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RemoveSite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {
        $server = $this->site->server;

        foreach ($this->site->daemons as $daemon) {
            $runTask->run($server, "Removing daemon {$daemon->programName()}", Script::render('daemons.remove', ['daemon' => $daemon]));
        }

        foreach ($this->site->scheduledJobs as $scheduledJob) {
            $runTask->run($server, 'Removing scheduled job', Script::render('scheduled-jobs.remove', ['scheduledJob' => $scheduledJob]));
        }

        $runTask->run($server, "Removing site {$this->site->domain}", Script::render('sites.remove', ['site' => $this->site]));

        $revokeSourceControlAccess = RevokeSourceControlAccess::forSites($server->user, [$this->site]);

        $this->site->delete();

        dispatch($revokeSourceControlAccess);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->update(['status' => ResourceStatus::Failed]);
    }
}
