<?php

use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerTuner;

function tuneServer(int $cores, int $memoryMb, int $diskGb = 80, bool $mysql = true, bool $redis = true): array
{
    return (new ServerTuner)->tune(new ServerSpecs($cores, $memoryMb, $diskGb), $mysql, $redis)->toArray();
}

test('the services on a mid-sized server fit within its memory', function (int $cores, int $memoryMb) {
    $tuning = tuneServer($cores, $memoryMb);

    $allocated = (new ServerTuner)->operatingSystemReserveMb(new ServerSpecs($cores, $memoryMb, 80))
        + $tuning['mysql']['innodb_buffer_pool_size_mb']
        + $tuning['redis']['maxmemory_mb']
        + $tuning['php']['primary_pool']['max_children'] * ServerTuner::PHP_PROCESS_SIZE_MB;

    expect($allocated)->toBeLessThanOrEqual($memoryMb);
})->with([
    '2 cores / 2GB' => [2, 2048],
    '2 cores / 4GB' => [2, 4096],
    '4 cores / 8GB' => [4, 8192],
    '8 cores / 16GB' => [8, 16384],
]);

test('larger servers get more php workers and a larger buffer pool', function () {
    $small = tuneServer(2, 2048);
    $large = tuneServer(8, 16384);

    expect($large['php']['primary_pool']['max_children'])->toBeGreaterThan($small['php']['primary_pool']['max_children'])
        ->and($large['mysql']['innodb_buffer_pool_size_mb'])->toBeGreaterThan($small['mysql']['innodb_buffer_pool_size_mb'])
        ->and($large['nginx']['worker_processes'])->toBe(8)
        ->and($large['nginx']['worker_connections'])->toBeGreaterThan($small['nginx']['worker_connections']);
});

test('tiny servers keep a minimum worker pool and disable the performance schema', function () {
    $tuning = tuneServer(1, 1024);

    expect($tuning['php']['primary_pool']['max_children'])->toBe(5)
        ->and($tuning['php']['primary_pool']['min_spare_servers'])->toBeGreaterThanOrEqual(1)
        ->and($tuning['php']['primary_pool']['max_spare_servers'])->toBeGreaterThan($tuning['php']['primary_pool']['min_spare_servers'])
        ->and($tuning['mysql']['performance_schema'])->toBeFalse()
        ->and($tuning['mysql']['innodb_buffer_pool_size_mb'])->toBe(256);
});

test('php workers are capped by cpu cores on memory-heavy servers', function () {
    expect(tuneServer(2, 65536)['php']['primary_pool']['max_children'])->toBe(40);
});

test('the buffer pool is sized in 128MB chunks', function (int $memoryMb) {
    expect(tuneServer(2, $memoryMb)['mysql']['innodb_buffer_pool_size_mb'] % 128)->toBe(0);
})->with([1024, 3000, 6144, 12000]);

test('mysql allows a connection for every php worker', function () {
    $tuning = tuneServer(4, 8192);

    expect($tuning['mysql']['max_connections'])->toBeGreaterThan($tuning['php']['primary_pool']['max_children']);
});

test('php receives the memory freed by skipping mysql and redis', function () {
    $full = tuneServer(4, 8192);
    $phpOnly = tuneServer(4, 8192, mysql: false, redis: false);

    expect($phpOnly['mysql'])->toBeNull()
        ->and($phpOnly['redis'])->toBeNull()
        ->and($phpOnly['kernel'])->not->toHaveKey('vm.overcommit_memory')
        ->and($phpOnly['php']['memory_budget_mb'])->toBeGreaterThan($full['php']['memory_budget_mb']);
});

test('swap never takes more than a tenth of the disk', function () {
    expect(tuneServer(1, 2048, diskGb: 10)['swap']['size_mb'])->toBe(1024)
        ->and(tuneServer(1, 2048, diskGb: 80)['swap']['size_mb'])->toBe(2048);
});

test('redis evicts only expiring keys so queued jobs are never dropped', function () {
    expect(tuneServer(2, 4096)['redis']['maxmemory_policy'])->toBe('volatile-lru');
});

test('the memory allocation accounts for every megabyte of the server', function () {
    $tuning = (new ServerTuner)->tune(new ServerSpecs(4, 8192, 160), true, true);

    $allocation = $tuning->memoryAllocation(8192);

    expect(array_column($allocation, 'service'))->toBe(['PHP-FPM', 'MySQL', 'Redis', 'System'])
        ->and(array_sum(array_column($allocation, 'mb')))->toBe(8192)
        ->and($allocation[1]['mb'])->toBe($tuning->mysql['innodb_buffer_pool_size_mb']);
});

test('the memory allocation leaves out services the server does not run', function () {
    $tuning = (new ServerTuner)->tune(new ServerSpecs(2, 4096, 80), false, false);

    expect(array_column($tuning->memoryAllocation(4096), 'service'))->toBe(['PHP-FPM', 'System']);
});
