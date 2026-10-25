# Cloudflare Tunnel, for installs given a tunnel token. cloudflared connects
# out to Cloudflare, so the firewall stops accepting HTTP and HTTPS and nginx
# only listens for cloudflared on the loopback interface.

if [ "${CLOUDFLARE_TUNNEL:-false}" != true ]; then
    return 0
fi

if ! grep -q '^TUNNEL_TOKEN=.' /etc/swage/cloudflared.env 2> /dev/null; then
    echo "No tunnel token in /etc/swage/cloudflared.env." >&2
    exit 1
fi

chmod 600 /etc/swage/cloudflared.env

mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' \
    > /etc/apt/sources.list.d/cloudflared.list

apt-get update -q
apt-get install -yq cloudflared

# systemd reads the token from the root-only file, so it stays out of the
# unit, the process list and the reach of the application user.
cat > /etc/systemd/system/swage-tunnel.service <<UNIT
[Unit]
Description=Cloudflare Tunnel for Swage
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
EnvironmentFile=/etc/swage/cloudflared.env
ExecStart=/usr/bin/cloudflared --no-autoupdate tunnel run
DynamicUser=yes
Restart=on-failure
RestartSec=5s
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable swage-tunnel
systemctl restart swage-tunnel

swage_write_vhost

ufw delete allow 'Nginx Full' > /dev/null 2>&1 || true
