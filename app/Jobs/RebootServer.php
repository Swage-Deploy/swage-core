<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebootServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Only one reboot of a server may be queued at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    /**
     * Reboot the server. Every service Swage installs starts again on boot, so sites come back on their own.
     */
    public function handle(RunServerTask $runTask): void
    {
        $runTask->run($this->server, 'Rebooting the server', Script::render('reboot'), timeout: 60);

        $this->server->update(['reboot_required_at' => null, 'reboot_required_packages' => null]);
    }
}
