<?php

namespace App\Provisioning;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The service configuration values calculated for a server's hardware.
 *
 * @implements Arrayable<string, array<string, mixed>>
 */
final readonly class ServerTuning implements Arrayable
{
    /**
     * @param  array{size_mb: int, swappiness: int, vfs_cache_pressure: int}  $swap
     * @param  array<string, int|string>  $kernel
     * @param  array{worker_processes: int, worker_connections: int, worker_rlimit_nofile: int, keepalive_timeout: int, client_max_body_size: string, open_file_cache_max: int}|null  $nginx
     * @param  array{memory_budget_mb: int, process_size_mb: int, primary_pool: array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int}, secondary_pool: array{pm: string, max_children: int, process_idle_timeout: string, max_requests: int}, memory_limit: string, upload_max_filesize: string, opcache_memory_mb: int, opcache_interned_strings_mb: int, opcache_max_accelerated_files: int, realpath_cache_size: string, octane_workers?: int}|null  $php
     * @param  array{innodb_buffer_pool_size_mb: int, innodb_buffer_pool_instances: int, innodb_redo_log_capacity_mb: int, innodb_io_threads: int, max_connections: int, tmp_table_size_mb: int, table_open_cache: int, performance_schema: bool}|null  $mysql
     * @param  array{maxmemory_mb: int, maxmemory_policy: string}|null  $redis
     * @param  array{shared_buffers_mb: int, effective_cache_size_mb: int, maintenance_work_mem_mb: int, work_mem_mb: int, wal_buffers_mb: int, min_wal_size_mb: int, max_wal_size_mb: int, max_worker_processes: int, max_parallel_workers: int, max_parallel_workers_per_gather: int, max_connections: int}|null  $postgres
     */
    public function __construct(
        public array $swap,
        public array $kernel,
        public ?array $nginx,
        public ?array $php,
        public ?array $mysql,
        public ?array $redis,
        public ?array $postgres = null,
    ) {}

    /**
     * Rebuild the tuning from its stored array form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            swap: $attributes['swap'],
            kernel: $attributes['kernel'],
            nginx: $attributes['nginx'] ?? null,
            php: $attributes['php'] ?? null,
            mysql: $attributes['mysql'] ?? null,
            redis: $attributes['redis'] ?? null,
            postgres: $attributes['postgres'] ?? null,
        );
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function toArray(): array
    {
        return [
            'swap' => $this->swap,
            'kernel' => $this->kernel,
            'nginx' => $this->nginx,
            'php' => $this->php,
            'mysql' => $this->mysql,
            'redis' => $this->redis,
            'postgres' => $this->postgres,
        ];
    }

    /**
     * How a server's memory is divided between its services, largest consumers first.
     *
     * Whatever the services are not budgeted is left to the operating system, page cache and smaller services.
     *
     * @return list<array{service: string, mb: int}>
     */
    public function memoryAllocation(int $memoryMb): array
    {
        $services = array_values(array_filter([
            $this->php ? ['service' => 'PHP-FPM', 'mb' => $this->php['memory_budget_mb']] : null,
            $this->mysql ? ['service' => 'MySQL', 'mb' => $this->mysql['innodb_buffer_pool_size_mb']] : null,
            $this->postgres ? ['service' => 'PostgreSQL', 'mb' => $this->postgres['shared_buffers_mb']] : null,
            $this->redis ? ['service' => 'Redis', 'mb' => $this->redis['maxmemory_mb']] : null,
        ]));

        $services[] = ['service' => 'System', 'mb' => max(0, $memoryMb - array_sum(array_column($services, 'mb')))];

        return $services;
    }
}
