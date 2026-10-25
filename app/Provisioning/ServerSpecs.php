<?php

namespace App\Provisioning;

final readonly class ServerSpecs
{
    public function __construct(
        public int $cpuCores,
        public int $memoryMb,
        public int $diskGb,
    ) {}
}
