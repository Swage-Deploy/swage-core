<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a server's CPU, memory, disk and load for its overview. Readings are not recorded in the server's activity
 * log, since they are taken every few minutes without changing anything.
 */
class RecordServerMetrics implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Execute the job. A server that cannot be reached is left without a reading, which shows as a gap.
     */
    public function handle(RemoteShell $shell): ?ServerMetric
    {
        try {
            $result = $shell->run($this->server, Script::render('server-metrics'), timeout: 30);
        } catch (Throwable) {
            return null;
        }

        $values = $result->successful() ? ServerMetric::parse($result->output) : null;

        return $values ? $this->server->metrics()->create($values) : null;
    }
}
