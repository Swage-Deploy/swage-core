@php($redisUser = $site->system_user)
@php($redisUnit = 'swage-redis@'.$site->system_user)
@php($redisTuning = $site->server->tuning['redis'] ?? ['maxmemory_mb' => 256, 'maxmemory_policy' => 'allkeys-lru'])
# {!! $site->domain !!} gets a Redis server of its own. It runs as the site's user and only listens on a unix socket
# in a directory nobody else can enter, so no other site can read, write or flush its data.
cat > /etc/systemd/system/swage-redis@.service <<'SWAGE_EOF'
[Unit]
Description=Private Redis server for %i
After=network.target

[Service]
Type=notify
User=%i
Group=%i
ExecStart=/usr/bin/redis-server /etc/swage-redis/%i.conf --supervised systemd --daemonize no
RuntimeDirectory=swage-redis-%i
RuntimeDirectoryMode=0700
Restart=always
LimitNOFILE=10032

[Install]
WantedBy=multi-user.target
SWAGE_EOF

# /etc/redis only lets the redis user in, so the site user could not read a config kept there.
install -d -m 755 /etc/swage-redis
cat > /etc/swage-redis/{!! $redisUser !!}.conf <<'SWAGE_EOF'
port 0
unixsocket {!! $site->redisSocket() !!}
unixsocketperm 700
dir /home/{!! $redisUser !!}/.swage/redis
maxmemory {!! max(64, intdiv($redisTuning['maxmemory_mb'], 4)) !!}mb
maxmemory-policy {!! $redisTuning['maxmemory_policy'] !!}
SWAGE_EOF
chown root:{!! $redisUser !!} /etc/swage-redis/{!! $redisUser !!}.conf
chmod 640 /etc/swage-redis/{!! $redisUser !!}.conf

sudo -u {!! $redisUser !!} mkdir -p /home/{!! $redisUser !!}/.swage/redis
sudo -u {!! $redisUser !!} chmod 700 /home/{!! $redisUser !!}/.swage/redis

systemctl daemon-reload
systemctl enable --quiet {!! $redisUnit !!}
systemctl restart {!! $redisUnit !!}

for attempt in $(seq 1 20); do
    [ -S '{!! $site->redisSocket() !!}' ] && break
    sleep 0.5
done
sudo -u {!! $redisUser !!} redis-cli -s '{!! $site->redisSocket() !!}' ping | grep -q PONG
