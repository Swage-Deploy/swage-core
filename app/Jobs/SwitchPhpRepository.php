<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SwitchPhpRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

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
        $runTask->run($this->server, 'Moving PHP to packages.sury.org', Script::render('php.switch-repository'), timeout: 300);
    }
}
