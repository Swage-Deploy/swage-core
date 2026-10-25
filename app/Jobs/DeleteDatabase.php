<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Database;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Database $database) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {

        $runTask->run($this->database->server, "Deleting database {$this->database->name}", Script::render('databases.delete', [
            'database' => $this->database,
        ]));

        $this->database->delete();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->database->update(['status' => ResourceStatus::Failed]);
    }
}
