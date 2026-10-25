<?php

namespace App\Jobs;

use App\Models\SelfUpdate;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts an update requested from the dashboard.
 *
 * PHP-FPM runs in a systemd sandbox that makes /etc and /usr read-only for everything it starts, sudo included,
 * so an update launched from a web request could not apply host migrations. The queue worker runs under
 * Supervisor without that sandbox, so the update is started from here instead.
 */
class StartSelfUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public SelfUpdate $update) {}

    /**
     * Execute the job.
     */
    public function handle(SelfUpdater $updater): void
    {
        $updater->spawn($this->update);
    }
}
