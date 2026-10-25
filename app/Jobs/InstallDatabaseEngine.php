<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\DatabaseEngine;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class InstallDatabaseEngine implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Server $server, public DatabaseEngine $engine) {}

    /**
     * Install a database engine on a server that was provisioned without it, then re-tune the server so the new
     * engine gets its share of memory alongside the services already running.
     */
    public function handle(RunServerTask $runTask): void
    {
        $runTask->run($this->server, "Installing {$this->engine->label()}", Script::render('databases.install-engine', [
            'server' => $this->server,
            'engine' => $this->engine,
        ]), timeout: 1200);

        $this->server->update([
            $this->engine === DatabaseEngine::Mysql ? 'has_mysql' : 'has_postgres' => true,
        ]);

        Bus::dispatchSync(new OptimizeServer($this->server));

        $this->server->update(['installing_database' => null]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->server->update(['installing_database' => null]);
    }
}
