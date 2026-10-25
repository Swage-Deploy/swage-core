<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncDatabaseAccess implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Open a database server's database ports to exactly the addresses currently allowed, in the firewall and in
     * PostgreSQL's client authentication.
     */
    public function handle(RunServerTask $runTask): void
    {
        $this->server->refresh();

        $runTask->run($this->server, 'Updating database access', Script::render('databases.sync-access', [
            'server' => $this->server,
        ]));
    }
}
