[mysqld]
bind-address = {!! implode(',', $server->databaseListenAddresses()) !!}
skip-name-resolve

innodb_buffer_pool_size = {!! $tuning->mysql['innodb_buffer_pool_size_mb'] !!}M
innodb_buffer_pool_instances = {!! $tuning->mysql['innodb_buffer_pool_instances'] !!}
innodb_redo_log_capacity = {!! $tuning->mysql['innodb_redo_log_capacity_mb'] !!}M
innodb_read_io_threads = {!! $tuning->mysql['innodb_io_threads'] !!}
innodb_write_io_threads = {!! $tuning->mysql['innodb_io_threads'] !!}
innodb_io_capacity = 1000
innodb_io_capacity_max = 2000
innodb_flush_method = O_DIRECT
innodb_flush_neighbors = 0

max_connections = {!! $tuning->mysql['max_connections'] !!}
tmp_table_size = {!! $tuning->mysql['tmp_table_size_mb'] !!}M
max_heap_table_size = {!! $tuning->mysql['tmp_table_size_mb'] !!}M
table_open_cache = {!! $tuning->mysql['table_open_cache'] !!}
performance_schema = {!! $tuning->mysql['performance_schema'] ? 'ON' : 'OFF' !!}
