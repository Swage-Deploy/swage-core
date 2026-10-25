<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerTuner;
use App\Provisioning\ServerTuning;

class ApplyServerSpecs
{
    public function __construct(protected ServerTuner $tuner) {}

    /**
     * Record the server's hardware and calculate service tuning for it.
     */
    public function handle(Server $server, ServerSpecs $specs): ServerTuning
    {
        $tuning = $this->tuner->tune($specs, withMysql: $server->usesMysql(), withRedis: $server->has_redis, withPostgres: $server->usesPostgres(), withPhp: $server->isAppServer());

        $server->update([
            'cpu_cores' => $specs->cpuCores,
            'memory_mb' => $specs->memoryMb,
            'disk_gb' => $specs->diskGb,
            'tuning' => $tuning->toArray(),
        ]);

        return $tuning;
    }
}
