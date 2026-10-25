; Managed by Swage: the isolated pool for {!! $site->domain !!}
[{!! $site->system_user !!}]
user = {!! $site->system_user !!}
group = {!! $site->system_user !!}
listen = {!! $site->fpmSocket() !!}
listen.owner = {!! $site->server->webUser() !!}
listen.group = {!! $site->server->webUser() !!}
listen.mode = 0660

pm = {!! $pool['pm'] !!}
pm.max_children = {!! $pool['max_children'] !!}
pm.process_idle_timeout = {!! $pool['process_idle_timeout'] !!}
pm.max_requests = {!! $pool['max_requests'] !!}

catch_workers_output = yes
@if ($phpValues !== [])

; The site's PHP settings. php_value lets the application still change them with ini_set().
@foreach ($phpValues as $name => $value)
php_value[{!! $name !!}] = {!! $value !!}
@endforeach
@endif
