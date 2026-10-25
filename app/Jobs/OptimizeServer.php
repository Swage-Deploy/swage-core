<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyServerSpecs;
use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Provisioning\ServerSpecs;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class OptimizeServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Only one re-optimization of a server may be queued at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    /**
     * Re-detect the server's hardware and rewrite every tuned service config.
     */
    public function handle(RunServerTask $runTask, ApplyServerSpecs $applySpecs): void
    {
        $detected = $runTask->run($this->server, 'Detecting server specs', Script::render('detect-specs'));

        $tuning = $applySpecs->handle($this->server, $this->parseSpecs($detected->output));

        $runTask->run($this->server, 'Optimizing services', Script::render('optimize-server', [
            'server' => $this->server,
            'tuning' => $tuning,
        ]));
    }

    protected function parseSpecs(string $output): ServerSpecs
    {
        preg_match_all('/^SWAGE_(CPU_CORES|MEMORY_MB|DISK_GB) (\d+)$/m', $output, $matches, PREG_SET_ORDER);

        $values = collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => (int) $match[2]]);

        if (! $values->has(['CPU_CORES', 'MEMORY_MB'])) {
            throw new RuntimeException('Unable to detect the server specs.');
        }

        return new ServerSpecs(
            cpuCores: $values['CPU_CORES'],
            memoryMb: $values['MEMORY_MB'],
            diskGb: $values->get('DISK_GB', 0),
        );
    }
}
