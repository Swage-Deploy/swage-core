# Sites take visitors' addresses from requests through Cloudflare's proxy, which only connects from these ranges.
# Cloudflare can add ranges, so a daily job refreshes them and keeps the current list if the new one cannot be used.
mkdir -p {!! dirname(App\Sites\CloudflareNetworks::CONFIG_PATH) !!}
if [ ! -s {!! App\Sites\CloudflareNetworks::CONFIG_PATH !!} ]; then
cat > {!! App\Sites\CloudflareNetworks::CONFIG_PATH !!} <<'SWAGE_EOF'
{!! rtrim(App\Sites\CloudflareNetworks::config()) !!}
SWAGE_EOF
fi

cat > /usr/local/sbin/swage-cloudflare-ips <<'SWAGE_EOF'
#!/usr/bin/env bash
# Refreshes the ranges Cloudflare's proxy connects from, which sites take visitors' addresses from.
set -euo pipefail

CONFIG={!! App\Sites\CloudflareNetworks::CONFIG_PATH !!}
NEW="$(mktemp)"
trap 'rm -f "$NEW" "$CONFIG.previous"' EXIT

# Fails, keeping the current list, if Cloudflare cannot be reached or sends no ranges.
ranges() {
    curl -fsS --max-time 30 "https://www.cloudflare.com/ips-$1/" | tr -d '\r' | grep -v '^[[:space:]]*$'
}

V4="$(ranges v4)"
V6="$(ranges v6)"

while read -r range; do
    if ! [[ "$range" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$ ]]; then
        echo "Cloudflare sent an unexpected IPv4 range: $range" >&2
        exit 1
    fi
    echo "set_real_ip_from $range;" >> "$NEW"
done <<< "$V4"

while read -r range; do
    if ! [[ "$range" =~ ^[0-9a-fA-F:]+/[0-9]{1,3}$ ]]; then
        echo "Cloudflare sent an unexpected IPv6 range: $range" >&2
        exit 1
    fi
    echo "set_real_ip_from $range;" >> "$NEW"
done <<< "$V6"

if cmp -s "$NEW" "$CONFIG"; then
    exit 0
fi

cp "$CONFIG" "$CONFIG.previous"
install -m 644 "$NEW" "$CONFIG"

if ! nginx -t -q; then
    mv "$CONFIG.previous" "$CONFIG"
    echo "nginx rejected Cloudflare's new ranges, so the previous ones were kept." >&2
    exit 1
fi

systemctl reload nginx
echo "Updated Cloudflare's ranges."
SWAGE_EOF
chown root:root /usr/local/sbin/swage-cloudflare-ips
chmod 755 /usr/local/sbin/swage-cloudflare-ips

cat > /etc/cron.d/swage-cloudflare-ips <<'SWAGE_EOF'
# Refresh the ranges Cloudflare's proxy connects from. Output goes to the journal, under swage-cloudflare-ips.
{!! $server->id % 60 !!} 4 * * * root /usr/local/sbin/swage-cloudflare-ips 2>&1 | logger -t swage-cloudflare-ips
SWAGE_EOF
chmod 644 /etc/cron.d/swage-cloudflare-ips
