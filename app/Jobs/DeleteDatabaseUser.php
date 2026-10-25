<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\DatabaseUser;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteDatabaseUser implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public DatabaseUser $databaseUser) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {

        $runTask->run($this->databaseUser->server, "Deleting database user {$this->databaseUser->username}", Script::render('databases.delete-user', [
            'databaseUser' => $this->databaseUser,
        ]));

        $this->databaseUser->delete();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->databaseUser->update(['status' => ResourceStatus::Failed]);
    }
}
