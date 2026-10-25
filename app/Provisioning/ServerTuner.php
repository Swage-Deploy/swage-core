<?php

namespace App\Provisioning;

/**
 * Calculates service configuration sized to a server's CPU, memory and disk.
 *
 * Every service on a Swage server shares the same machine, so memory is
 * budgeted in a fixed order: the operating system is reserved first, then
 * MySQL's buffer pool or PostgreSQL's shared buffers and Redis are sized as a
 * share of total memory, and whatever remains is handed to PHP-FPM workers.
 */
class ServerTuner
{
    /**
     * The assumed resident memory of a single PHP-FPM worker running a
     * typical Laravel application, in megabytes.
     */
    public const int PHP_PROCESS_SIZE_MB = 64;

    /**
     * Memory used by nginx, supervisor, cron and other small daemons.
     */
    protected const int AUXILIARY_SERVICES_MB = 64;

    /**
     * The assumed resident memory of an Octane worker. Workers keep the
     * framework booted between requests, so they hold more than FPM.
     */
    public const int OCTANE_WORKER_SIZE_MB = 128;

    /**
     * PostgreSQL memory used outside shared buffers by its background processes and idle connections.
     */
    protected const int POSTGRES_OVERHEAD_MB = 150;

    /**
     * Calculate the tuning for a server. A database server runs no nginx or PHP, so its databases get the memory PHP
     * would have had and accept connections from app servers instead of local PHP workers.
     */
    public function tune(ServerSpecs $specs, bool $withMysql, bool $withRedis, bool $withPostgres = false, bool $withPhp = true): ServerTuning
    {
        // A server running both engines splits their memory instead of giving each its usual share.
        $both = $withMysql && $withPostgres;

        $mysql = $withMysql ? $this->mysql($specs, match (true) {
            ! $withPhp => $both ? 0.35 : 0.6,
            default => $both ? 0.2 : 0.3,
        }) : null;
        $postgres = $withPostgres ? $this->postgres($specs, $both ? 0.15 : 0.25, $withPhp ? 0.5 : 0.75) : null;
        $redis = $withRedis ? $this->redis($specs) : null;

        $php = $withPhp ? $this->php($specs, $this->phpMemoryBudget($specs, $mysql, $postgres, $redis)) : null;

        if ($mysql !== null) {
            $mysql['max_connections'] = $php !== null
                ? $this->mysqlMaxConnections($php['primary_pool']['max_children'])
                : $this->dedicatedMaxConnections($specs, 1000);
        }

        if ($postgres !== null) {
            $postgres['max_connections'] = $php !== null
                ? $this->postgresMaxConnections($php['primary_pool']['max_children'])
                : $this->dedicatedMaxConnections($specs, 500);
        }

        return new ServerTuning(
            swap: $this->swap($specs),
            kernel: $this->kernel($specs, $withRedis),
            nginx: $withPhp ? $this->nginx($specs) : null,
            php: $php,
            mysql: $mysql,
            redis: $redis,
            postgres: $postgres,
        );
    }

    /**
     * Memory set aside for the kernel, page cache and system services.
     */
    public function operatingSystemReserveMb(ServerSpecs $specs): int
    {
        return $this->clamp((int) round($specs->memoryMb * 0.1), 256, 1024);
    }

    /**
     * @return array{size_mb: int, swappiness: int, vfs_cache_pressure: int}
     */
    protected function swap(ServerSpecs $specs): array
    {
        $size = match (true) {
            $specs->memoryMb <= 2048 => max(1024, $specs->memoryMb),
            $specs->memoryMb <= 8192 => 2048,
            default => 4096,
        };

        if ($specs->diskGb > 0) {
            $size = min($size, (int) floor($specs->diskGb * 1024 * 0.1));
        }

        return [
            'size_mb' => max(512, $size),
            'swappiness' => 10,
            'vfs_cache_pressure' => 50,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    protected function kernel(ServerSpecs $specs, bool $withRedis): array
    {
        return array_filter([
            'net.core.somaxconn' => 65535,
            'net.core.netdev_max_backlog' => 65535,
            'net.ipv4.tcp_max_syn_backlog' => 65535,
            'net.ipv4.ip_local_port_range' => '1024 65535',
            'net.ipv4.tcp_fin_timeout' => 15,
            'net.ipv4.tcp_tw_reuse' => 1,
            'fs.file-max' => 2097152,
            'vm.overcommit_memory' => $withRedis ? 1 : null,
        ], fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * @return array{worker_processes: int, worker_connections: int, worker_rlimit_nofile: int, keepalive_timeout: int, client_max_body_size: string, open_file_cache_max: int}
     */
    protected function nginx(ServerSpecs $specs): array
    {
        $connections = match (true) {
            $specs->memoryMb < 2048 => 1024,
            $specs->memoryMb < 8192 => 2048,
            default => 4096,
        };

        return [
            'worker_processes' => max(1, $specs->cpuCores),
            'worker_connections' => $connections,
            'worker_rlimit_nofile' => $connections * 2,
            'keepalive_timeout' => 15,
            'client_max_body_size' => '100m',
            'open_file_cache_max' => $connections * 4,
        ];
    }

    /**
     * Memory left over for PHP-FPM once every other service is accounted for.
     *
     * @param  array<string, mixed>|null  $mysql
     * @param  array<string, mixed>|null  $postgres
     * @param  array<string, mixed>|null  $redis
     */
    protected function phpMemoryBudget(ServerSpecs $specs, ?array $mysql, ?array $postgres, ?array $redis): int
    {
        $used = $this->operatingSystemReserveMb($specs) + self::AUXILIARY_SERVICES_MB;

        if ($mysql !== null) {
            $used += $mysql['innodb_buffer_pool_size_mb'] + $this->mysqlOverheadMb($mysql['performance_schema']);
        }

        if ($postgres !== null) {
            $used += $postgres['shared_buffers_mb'] + self::POSTGRES_OVERHEAD_MB;
        }

        if ($redis !== null) {
            // Redis can use roughly 20% more than maxmemory for fragmentation and buffers.
            $used += (int) ceil($redis['maxmemory_mb'] * 1.2);
        }

        return max((int) floor($specs->memoryMb * 0.25), $specs->memoryMb - $used);
    }

    /**
     * @return array{memory_budget_mb: int, process_size_mb: int, primary_pool: array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int}, secondary_pool: array{pm: string, max_children: int, process_idle_timeout: string, max_requests: int}, memory_limit: string, upload_max_filesize: string, opcache_memory_mb: int, opcache_interned_strings_mb: int, opcache_max_accelerated_files: int, realpath_cache_size: string, octane_workers: int}
     */
    protected function php(ServerSpecs $specs, int $budget): array
    {
        $cores = max(1, $specs->cpuCores);

        // Memory decides how many workers fit; the CPU ceiling stops a large
        // box with few cores from thrashing on context switches.
        $maxChildren = $this->clamp(intdiv($budget, self::PHP_PROCESS_SIZE_MB), 5, $cores * 20);

        $minSpare = $this->clamp($cores * 2, 1, intdiv($maxChildren, 4) ?: 1);
        $maxSpare = $this->clamp($cores * 4, $minSpare + 1, max($minSpare + 1, intdiv($maxChildren, 2)));

        $opcacheMemory = match (true) {
            $specs->memoryMb < 2048 => 128,
            $specs->memoryMb < 8192 => 256,
            default => 512,
        };

        return [
            'memory_budget_mb' => $budget,
            'process_size_mb' => self::PHP_PROCESS_SIZE_MB,
            'primary_pool' => [
                'pm' => 'dynamic',
                'max_children' => $maxChildren,
                'start_servers' => intdiv($minSpare + $maxSpare, 2),
                'min_spare_servers' => $minSpare,
                'max_spare_servers' => $maxSpare,
                'max_requests' => 500,
            ],
            'secondary_pool' => [
                'pm' => 'ondemand',
                'max_children' => max(5, intdiv($maxChildren, 2)),
                'process_idle_timeout' => '10s',
                'max_requests' => 500,
            ],
            'memory_limit' => '512M',
            'upload_max_filesize' => '100M',
            'opcache_memory_mb' => $opcacheMemory,
            'opcache_interned_strings_mb' => intdiv($opcacheMemory, 8),
            'opcache_max_accelerated_files' => 20000,
            'realpath_cache_size' => '4096K',
            // Octane workers never block on process startup, so one per core
            // saturates the CPU; memory still caps them on small servers.
            'octane_workers' => $this->clamp($cores, 1, max(1, intdiv($budget, self::OCTANE_WORKER_SIZE_MB))),
        ];
    }

    /**
     * @return array{innodb_buffer_pool_size_mb: int, innodb_buffer_pool_instances: int, innodb_redo_log_capacity_mb: int, innodb_io_threads: int, max_connections: int, tmp_table_size_mb: int, table_open_cache: int, performance_schema: bool}
     */
    protected function mysql(ServerSpecs $specs, float $memoryShare): array
    {
        // The buffer pool is sized in 128MB chunks to match InnoDB's chunk size.
        $bufferPool = max(128, intdiv((int) floor($specs->memoryMb * $memoryShare), 128) * 128);

        return [
            'innodb_buffer_pool_size_mb' => $bufferPool,
            'innodb_buffer_pool_instances' => $this->clamp(intdiv($bufferPool, 1024), 1, 8),
            'innodb_redo_log_capacity_mb' => $this->clamp(intdiv($bufferPool, 4), 128, 2048),
            'innodb_io_threads' => $this->clamp($specs->cpuCores, 4, 16),
            'max_connections' => 151,
            'tmp_table_size_mb' => match (true) {
                $specs->memoryMb < 2048 => 16,
                $specs->memoryMb < 8192 => 32,
                default => 64,
            },
            'table_open_cache' => $specs->memoryMb < 2048 ? 400 : 2000,
            // The performance schema reserves several hundred megabytes up front,
            // which small servers cannot spare.
            'performance_schema' => $specs->memoryMb >= 4096,
        ];
    }

    /**
     * MySQL memory used outside the buffer pool.
     */
    protected function mysqlOverheadMb(bool $performanceSchema): int
    {
        return $performanceSchema ? 400 : 150;
    }

    /**
     * Allow every PHP worker a connection, plus headroom for queue workers and the CLI.
     */
    protected function mysqlMaxConnections(int $phpMaxChildren): int
    {
        return $this->clamp((int) ceil($phpMaxChildren * 1.5) + 20, 100, 1000);
    }

    /**
     * @return array{shared_buffers_mb: int, effective_cache_size_mb: int, maintenance_work_mem_mb: int, work_mem_mb: int, wal_buffers_mb: int, min_wal_size_mb: int, max_wal_size_mb: int, max_worker_processes: int, max_parallel_workers: int, max_parallel_workers_per_gather: int, max_connections: int}
     */
    protected function postgres(ServerSpecs $specs, float $memoryShare, float $cacheShare): array
    {
        $cores = max(1, $specs->cpuCores);
        $maxWal = $specs->diskGb > 0 && $specs->diskGb < 40 ? 1024 : 4096;

        return [
            'shared_buffers_mb' => max(128, (int) floor($specs->memoryMb * $memoryShare)),
            // A planner hint for how much the page cache holds, not memory PostgreSQL takes.
            'effective_cache_size_mb' => max(256, (int) floor($specs->memoryMb * $cacheShare)),
            'maintenance_work_mem_mb' => $this->clamp(intdiv($specs->memoryMb, 16), 64, 2048),
            'work_mem_mb' => match (true) {
                $specs->memoryMb < 2048 => 4,
                $specs->memoryMb < 8192 => 8,
                default => 16,
            },
            'wal_buffers_mb' => 16,
            'min_wal_size_mb' => intdiv($maxWal, 4),
            'max_wal_size_mb' => $maxWal,
            'max_worker_processes' => max(8, $cores),
            'max_parallel_workers' => $cores,
            'max_parallel_workers_per_gather' => $this->clamp(intdiv($cores, 2), 1, 4),
            'max_connections' => 100,
        ];
    }

    /**
     * Allow every PHP worker a connection, plus headroom for queue workers and the CLI. Each PostgreSQL connection is
     * a process of its own, so the ceiling is lower than MySQL's.
     */
    protected function postgresMaxConnections(int $phpMaxChildren): int
    {
        return $this->clamp((int) ceil($phpMaxChildren * 1.5) + 20, 100, 500);
    }

    /**
     * Connections a database server accepts from app servers, which grow with its memory.
     */
    protected function dedicatedMaxConnections(ServerSpecs $specs, int $ceiling): int
    {
        return $this->clamp(intdiv($specs->memoryMb, 16), 100, $ceiling);
    }

    /**
     * @return array{maxmemory_mb: int, maxmemory_policy: string}
     */
    protected function redis(ServerSpecs $specs): array
    {
        return [
            'maxmemory_mb' => $this->clamp((int) round($specs->memoryMb * 0.1), 64, 4096),
            // Only keys with an expiry are evicted, so queued jobs and
            // sessions are never silently dropped under memory pressure.
            'maxmemory_policy' => 'volatile-lru',
        ];
    }

    protected function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
