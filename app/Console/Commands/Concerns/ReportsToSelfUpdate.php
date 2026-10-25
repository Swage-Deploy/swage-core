<?php

namespace App\Console\Commands\Concerns;

use App\Enums\DeploymentStatus;
use App\Models\SelfUpdate;

/**
 * Lets a command started from the dashboard write its progress to the update record the dashboard shows, as well as
 * to the console.
 */
trait ReportsToSelfUpdate
{
    protected ?SelfUpdate $selfUpdate = null;

    /**
     * Load the dashboard's update record, if the command was started from the dashboard, and mark it as running.
     */
    protected function startSelfUpdate(): void
    {
        if ($id = $this->option('update')) {
            $this->selfUpdate = SelfUpdate::findOrFail($id);
            $this->selfUpdate->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);
        }
    }

    protected function report(string $message): void
    {
        $this->line($message);
        $this->selfUpdate?->log($message."\n");
    }

    /**
     * Report an error and mark the dashboard's update record as failed.
     */
    protected function stop(string $message): int
    {
        $this->components->error($message);
        $this->selfUpdate?->log("\n".$message."\n");

        if ($this->selfUpdate && $this->selfUpdate->fresh()->status->isInProgress()) {
            $this->selfUpdate->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);
        }

        return self::FAILURE;
    }

    /**
     * Rebuild Swage with the change, as part of the dashboard's update record when there is one.
     */
    protected function rebuild(): bool
    {
        return $this->call('swage:update', [
            '--force' => true,
            ...($this->selfUpdate ? ['--update' => $this->selfUpdate->id] : []),
        ]) === self::SUCCESS;
    }
}
