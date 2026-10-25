<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Daemon;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallDaemon implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Daemon $daemon) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {
        $this->daemon->update(['status' => ResourceStatus::Installing]);

        $runTask->run($this->daemon->server, "Installing daemon {$this->daemon->programName()}", Script::render('daemons.install', [
            'daemon' => $this->daemon,
        ]));

        $this->daemon->update(['status' => ResourceStatus::Installed]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->daemon->update(['status' => ResourceStatus::Failed]);
    }
}
