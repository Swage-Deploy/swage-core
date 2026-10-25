@php($configFiles = new App\Provisioning\ServiceConfigFiles($server, $tuning))
# ---------------------------------------------------------------------------
# Service tuning for {!! $server->cpu_cores !!} CPU core(s) / {!! $server->memory_mb !!}MB memory / {!! $server->disk_gb !!}GB disk
# ---------------------------------------------------------------------------

# Containers share their host's kernel, so swap, kernel settings and huge pages belong to the host.
swage_in_container() {
    systemd-detect-virt --quiet --container
}

# Swap ({!! $tuning->swap['size_mb'] !!}MB)
if swage_in_container; then
    echo "Skipped swap: this server is a container, and swap is set on the machine it runs on."
else
    SWAP_BYTES=$(({!! $tuning->swap['size_mb'] !!} * 1024 * 1024))
    if [ ! -f /swapfile ] || [ "$(stat -c %s /swapfile)" != "$SWAP_BYTES" ]; then
        swapoff /swapfile 2> /dev/null || true
        rm -f /swapfile
        fallocate -l {!! $tuning->swap['size_mb'] !!}M /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
        swapon /swapfile
    fi
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Kernel
cat > /etc/sysctl.d/99-swage.conf <<'SWAGE_EOF'
vm.swappiness = {!! $tuning->swap['swappiness'] !!}
vm.vfs_cache_pressure = {!! $tuning->swap['vfs_cache_pressure'] !!}
@foreach ($tuning->kernel as $key => $value)
{!! $key !!} = {!! $value !!}
@endforeach
SWAGE_EOF
if swage_in_container; then
    # Only the settings namespaced to the container can be changed, so each is applied on its own and the rest, which
    # belong to the machine the container runs on, are named.
    SWAGE_SKIPPED=""
    while IFS= read -r SWAGE_SETTING; do
        [ -n "$SWAGE_SETTING" ] || continue
        sysctl -q -w "${SWAGE_SETTING// = /=}" > /dev/null 2>&1 || SWAGE_SKIPPED="$SWAGE_SKIPPED ${SWAGE_SETTING%% = *}"
    done < /etc/sysctl.d/99-swage.conf
    if [ -n "$SWAGE_SKIPPED" ]; then
        echo "Skipped kernel settings that only the machine running this container can change:$SWAGE_SKIPPED"
    fi
else
    sysctl --system > /dev/null
fi

@if ($tuning->nginx)
# nginx
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::Nginx)])

# nginx workers need to own nginx's temp directories for request buffering.
chown -R {!! $server->webUser() !!}:{!! $server->webUser() !!} /var/lib/nginx

# PHP
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::PhpFpmPool)])
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::PhpFpmIni)])
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::PhpCliIni)])
@foreach ($server->sites()->whereNotNull('system_user')->get() as $isolatedSite)
@include('scripts.partials.write-files', ['files' => (new App\Sites\SitePhpPool($isolatedSite, $tuning))->files()])
@endforeach
@endif

@if ($tuning->mysql)
# MySQL
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::Mysql)])
# Ubuntu's mysqld.cnf is read after Swage's and would put bind-address back on 127.0.0.1.
sed -i -E 's/^[[:space:]]*bind-address/# &/' /etc/mysql/mysql.conf.d/mysqld.cnf

@endif
@if ($tuning->postgres)
# PostgreSQL
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::Postgres)])

@endif
@if ($tuning->redis)
# Redis
@include('scripts.partials.write-files', ['files' => $configFiles->files(App\Enums\ConfigService::Redis)])
grep -q '^include /etc/redis/swage.conf' /etc/redis/redis.conf || echo 'include /etc/redis/swage.conf' >> /etc/redis/redis.conf

# Redis latency suffers with transparent huge pages enabled.
if ! swage_in_container; then
cat > /etc/systemd/system/disable-thp.service <<'SWAGE_EOF'
[Unit]
Description=Disable transparent huge pages
Before=redis-server.service

[Service]
Type=oneshot
ExecStart=/bin/sh -c 'echo never > /sys/kernel/mm/transparent_hugepage/enabled'

[Install]
WantedBy=multi-user.target
SWAGE_EOF
systemctl daemon-reload
systemctl enable --now disable-thp.service
fi

@endif
@if ($server->isDatabaseServer())
# Database access
@include('scripts.databases.partials.access')

@endif
# Apply
@if ($tuning->nginx)
nginx -t
systemctl reload nginx
@foreach ($server->php_versions as $php)
systemctl restart {!! $php->fpmService() !!}
@endforeach
@endif
@if ($tuning->mysql)
systemctl restart mysql
@endif
@if ($tuning->postgres)
systemctl restart postgresql
@endif
@if ($tuning->redis)
systemctl restart redis-server
@endif
