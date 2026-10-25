#!/usr/bin/env bash
#
# Swage provisioning script for {!! $server->name !!}
# Tuned for {!! $server->cpu_cores !!} CPU core(s) and {!! $server->memory_mb !!}MB of memory.
#
set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

SWAGE_USER='{!! $user !!}'

swage_callback() {
    curl -fsS -X POST "$1" "${@:2}" > /dev/null || true
}

swage_step() {
    echo
    echo "==> $1"
    swage_callback '{!! route('provision.progress', $server->provision_token) !!}' --data-urlencode "step=$1"
}

swage_failed() {
    swage_callback '{!! route('provision.failed', $server->provision_token) !!}' --data-urlencode "line=$1"
}

trap 'swage_failed $LINENO' ERR

# ---------------------------------------------------------------------------
swage_step 'Updating system packages'
# ---------------------------------------------------------------------------

apt-get update -q
apt-get upgrade -yq -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
apt-get install -yq software-properties-common curl gnupg ca-certificates git unzip zip acl jq \
    supervisor cron ufw certbot

@include('scripts.partials.unattended-upgrades')

timedatectl set-timezone UTC

# ---------------------------------------------------------------------------
swage_step 'Creating the {!! $user !!} user'
# ---------------------------------------------------------------------------

if ! id "$SWAGE_USER" > /dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$SWAGE_USER"
fi

for home in /root "/home/$SWAGE_USER"; do
    mkdir -p "$home/.ssh"
    touch "$home/.ssh/authorized_keys"
    grep -qF '{!! $server->ssh_public_key !!}' "$home/.ssh/authorized_keys" \
        || echo '{!! $server->ssh_public_key !!}' >> "$home/.ssh/authorized_keys"
    ssh-keyscan -H github.com >> "$home/.ssh/known_hosts" 2>/dev/null
    chmod 700 "$home/.ssh"
    chmod 600 "$home/.ssh/authorized_keys"
done

chown -R "$SWAGE_USER:$SWAGE_USER" "/home/$SWAGE_USER"
chmod 750 "/home/$SWAGE_USER"
mkdir -p "/home/$SWAGE_USER/.letsencrypt"
chown "$SWAGE_USER:$SWAGE_USER" "/home/$SWAGE_USER/.letsencrypt"

# Deployments may reload PHP-FPM and restart their own Supervisor programs without a password.
@include('scripts.partials.sudo-helpers')
@include('scripts.partials.sudoers', ['sudoUser' => $user, 'sudoersFile' => 'swage'])

# ---------------------------------------------------------------------------
swage_step 'Configuring the firewall'
# ---------------------------------------------------------------------------

ufw allow {!! $server->ssh_port !!}/tcp
@if ($server->isAppServer())
ufw allow 80/tcp
ufw allow 443/tcp
@endif
ufw --force enable

@if ($server->isAppServer())
# ---------------------------------------------------------------------------
swage_step 'Installing nginx'
# ---------------------------------------------------------------------------

apt-get install -yq nginx
rm -f /etc/nginx/sites-enabled/default

# nginx runs as www-data, which runs no application code. It joins the group of each user whose sites it serves.
usermod -aG "$SWAGE_USER" www-data

cat > /etc/nginx/sites-available/000-catch-all <<'SWAGE_EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
SWAGE_EOF
ln -sf /etc/nginx/sites-available/000-catch-all /etc/nginx/sites-enabled/000-catch-all

@include('scripts.partials.cloudflare-networks')
/usr/local/sbin/swage-cloudflare-ips || echo "Could not refresh Cloudflare's ranges now. The daily refresh tries again."

# ---------------------------------------------------------------------------
swage_step 'Installing PHP {!! $server->php_versions->map->value->implode(', ') !!}'
# ---------------------------------------------------------------------------

add-apt-repository -y ppa:ondrej/php
apt-get update -q

@foreach ($server->php_versions as $php)
apt-get install -yq {!! collect(['fpm', 'cli', 'common', 'mysql', 'pgsql', 'sqlite3', 'redis', 'mbstring', 'xml', 'curl', 'zip', 'bcmath', 'intl', 'gd', 'readline'])->map(fn ($extension) => "php{$php->value}-{$extension}")->implode(' ') !!}
@endforeach

update-alternatives --set php /usr/bin/php{!! $server->default_php_version->value !!}

curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ---------------------------------------------------------------------------
swage_step 'Installing Node.js {!! config('swage.node_version') !!}'
# ---------------------------------------------------------------------------

curl -fsSL https://deb.nodesource.com/setup_{!! config('swage.node_version') !!}.x | bash -
apt-get install -yq nodejs
@endif

@if ($server->usesMysql())
# ---------------------------------------------------------------------------
swage_step 'Installing MySQL'
# ---------------------------------------------------------------------------

@include('scripts.partials.install-mysql')
@endif

@if ($server->usesPostgres())
# ---------------------------------------------------------------------------
swage_step 'Installing PostgreSQL{!! $server->has_pgvector ? ' with pgvector' : '' !!}'
# ---------------------------------------------------------------------------

@include('scripts.partials.install-postgres')
@endif

@if ($server->has_redis)
# ---------------------------------------------------------------------------
swage_step 'Installing Redis'
# ---------------------------------------------------------------------------

apt-get install -yq redis-server
@endif

# ---------------------------------------------------------------------------
swage_step 'Optimizing services for this server'
# ---------------------------------------------------------------------------

@include('scripts.optimize')

systemctl enable --now supervisor cron

trap - ERR

swage_callback '{!! route('provision.finished', $server->provision_token) !!}'

echo
echo "==> Provisioning complete"
