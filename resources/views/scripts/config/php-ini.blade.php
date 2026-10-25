memory_limit = {!! $fpm ? $tuning->php['memory_limit'] : '-1' !!}
upload_max_filesize = {!! $tuning->php['upload_max_filesize'] !!}
post_max_size = {!! $tuning->php['upload_max_filesize'] !!}
expose_php = Off
realpath_cache_size = {!! $tuning->php['realpath_cache_size'] !!}
realpath_cache_ttl = 600
@if ($fpm)

opcache.enable = 1
opcache.memory_consumption = {!! $tuning->php['opcache_memory_mb'] !!}
opcache.interned_strings_buffer = {!! $tuning->php['opcache_interned_strings_mb'] !!}
opcache.max_accelerated_files = {!! $tuning->php['opcache_max_accelerated_files'] !!}
; Deployments reload PHP-FPM, so files never need to be re-checked on each request.
opcache.validate_timestamps = 0
opcache.save_comments = 1
@endif
