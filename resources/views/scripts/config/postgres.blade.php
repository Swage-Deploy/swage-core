listen_addresses = '{!! implode(',', $server->databaseListenAddresses()) !!}'

shared_buffers = {!! $tuning->postgres['shared_buffers_mb'] !!}MB
effective_cache_size = {!! $tuning->postgres['effective_cache_size_mb'] !!}MB
maintenance_work_mem = {!! $tuning->postgres['maintenance_work_mem_mb'] !!}MB
work_mem = {!! $tuning->postgres['work_mem_mb'] !!}MB

wal_buffers = {!! $tuning->postgres['wal_buffers_mb'] !!}MB
min_wal_size = {!! $tuning->postgres['min_wal_size_mb'] !!}MB
max_wal_size = {!! $tuning->postgres['max_wal_size_mb'] !!}MB
checkpoint_completion_target = 0.9

random_page_cost = 1.1
effective_io_concurrency = 200

max_worker_processes = {!! $tuning->postgres['max_worker_processes'] !!}
max_parallel_workers = {!! $tuning->postgres['max_parallel_workers'] !!}
max_parallel_workers_per_gather = {!! $tuning->postgres['max_parallel_workers_per_gather'] !!}

max_connections = {!! $tuning->postgres['max_connections'] !!}
