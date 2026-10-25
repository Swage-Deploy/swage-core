<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\ScheduledJob;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RemoveScheduledJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public ScheduledJob $scheduledJob) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {

        $runTask->run($this->scheduledJob->server, "Removing scheduled job #{$this->scheduledJob->id}", Script::render('scheduled-jobs.remove', [
            'scheduledJob' => $this->scheduledJob,
        ]));

        $this->scheduledJob->delete();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->scheduledJob->update(['status' => ResourceStatus::Failed]);
    }
}
