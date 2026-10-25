<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpgradeServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Only one upgrade run per server may be queued at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    /**
     * Apply each pending upgrade in order, recording it as soon as it succeeds so a failure can be retried from there.
     */
    public function handle(): void
    {
        foreach ($this->server->pendingUpgrades() as $id => $upgrade) {
            $upgrade->apply($this->server);

            $this->server->refresh()->markUpgradeApplied($id);
        }
    }
}
