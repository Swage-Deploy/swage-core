[www]
user = {!! $user !!}
group = {!! $user !!}
listen = {!! $php->fpmSocket() !!}
listen.owner = {!! $server->webUser() !!}
listen.group = {!! $server->webUser() !!}
listen.mode = 0660

pm = {!! $pool['pm'] !!}
pm.max_children = {!! $pool['max_children'] !!}
@if ($pool['pm'] === 'dynamic')
pm.start_servers = {!! $pool['start_servers'] !!}
pm.min_spare_servers = {!! $pool['min_spare_servers'] !!}
pm.max_spare_servers = {!! $pool['max_spare_servers'] !!}
@else
pm.process_idle_timeout = {!! $pool['process_idle_timeout'] !!}
@endif
pm.max_requests = {!! $pool['max_requests'] !!}

catch_workers_output = yes
