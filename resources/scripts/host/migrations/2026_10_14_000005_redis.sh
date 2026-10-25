# Redis, listening on localhost only.

apt-get install -yq redis-server

cat > /etc/redis/swage.conf <<CONF
bind 127.0.0.1 -::1
protected-mode yes
supervised systemd
CONF

grep -q '^include /etc/redis/swage.conf' /etc/redis/redis.conf || echo 'include /etc/redis/swage.conf' >> /etc/redis/redis.conf

systemctl restart redis-server
