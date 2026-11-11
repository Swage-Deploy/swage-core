<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MovePhpRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    /**
     * A lock left by a job that never ran expires, so the next hourly check queues it again.
     */
    public int $uniqueFor = 3600;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    /**
     * Install and update PHP from packages.sury.org instead of the ondrej PPA, which is being retired. The packages are
     * the same, so nothing installed changes until the next update.
     */
    public function handle(RunServerTask $runTask): void
    {
        $runTask->run($this->server, 'Moving PHP to packages.sury.org', Script::render('php.move-repository'), timeout: 300);

        $this->server->update(['php_repository_moved_at' => now()]);
    }
}
