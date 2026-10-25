<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\DatabaseEngine;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallPgvector implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Install pgvector on a server's PostgreSQL and enable it in every database Swage created there.
     */
    public function handle(RunServerTask $runTask): void
    {
        $databases = $this->server->databases()
            ->where('engine', DatabaseEngine::Postgres)
            ->get()
            ->reject->isSystemDatabase();

        $runTask->run($this->server, 'Installing pgvector', Script::render('databases.install-pgvector', [
            'databases' => $databases,
        ]));

        $this->server->update(['has_pgvector' => true, 'installing_database' => null]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->server->update(['installing_database' => null]);
    }
}
