bind 127.0.0.1 -::1
supervised systemd
maxmemory {!! $tuning->redis['maxmemory_mb'] !!}mb
maxmemory-policy {!! $tuning->redis['maxmemory_policy'] !!}
@if ($server->usesRedisAuthentication())
# Sites authenticate as {!! $user !!}. Isolated sites can be given a Redis server of their own instead.
user default off
user {!! $user !!} on #{!! hash('sha256', $server->redis_password) !!} ~* &* +@all
@endif
include {!! App\Provisioning\ServiceConfigFiles::REDIS_OVERRIDE_PATH !!}
