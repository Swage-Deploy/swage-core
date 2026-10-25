#!/usr/bin/env bash
#
# Installs Swage on a fresh Ubuntu 24.04 server. As root on the server:
#
#   apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/install-swage.sh -o install-swage.sh && bash install-swage.sh
#
# Installs from https://github.com/Swage-Deploy/swage-core. To install a fork
# instead, set SWAGE_REPOSITORY when running the script, and SWAGE_BRANCH when
# it is released from a branch other than main. A private fork is cloned over
# SSH, such as git@github.com:you/swage.git, and the installer creates a deploy
# key for it.
#
# To serve Swage through a Cloudflare Tunnel instead of opening ports 80 and
# 443, create a tunnel in Cloudflare, route its public hostname for Swage's
# domain to http://localhost:80, and pass the tunnel's token:
#
#   bash install-swage.sh --cloudflare-tunnel-token <token>
#
# Cloudflare then serves HTTPS, so no Let's Encrypt certificate is requested.
#
# The server itself is configured by host migrations in
# resources/scripts/host/migrations. A fresh install applies all of them, and
# every self-update applies any added since, so changes to how the server is
# set up reach existing installs too. To change the server, add a new file to
# that directory rather than editing this installer or an applied migration.
#
# Swage is installed in the release layout its self-updater manages:
#
#   /home/swage/app/releases/<timestamp>   one git clone per release
#   /home/swage/app/shared/.env            linked into every release
#   /home/swage/app/shared/storage         linked into every release
#   /home/swage/app/current                symlink to the live release
#
set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

REPOSITORY=${SWAGE_REPOSITORY:-https://github.com/Swage-Deploy/swage-core.git}
# The branch releases are tagged on. Only a fork released from another branch needs SWAGE_BRANCH.
BRANCH=${SWAGE_BRANCH:-main}
APP_USER=swage
APP_ROOT="/home/$APP_USER/app"
SOURCE=/opt/swage
PHP_VERSION=8.5
NODE_VERSION=22
CLOUDFLARE_TUNNEL_TOKEN=

while [ "$#" -gt 0 ]; do
    case "$1" in
        --cloudflare-tunnel-token=*)
            CLOUDFLARE_TUNNEL_TOKEN=${1#*=}
            ;;
        --cloudflare-tunnel-token)
            if [ "$#" -lt 2 ]; then
                echo "--cloudflare-tunnel-token needs the tunnel's token." >&2
                exit 1
            fi
            CLOUDFLARE_TUNNEL_TOKEN=$2
            shift
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
    shift
done

# Tokens are base64. Anything else would be written into a systemd environment file as is.
if ! [[ "$CLOUDFLARE_TUNNEL_TOKEN" =~ ^[A-Za-z0-9+/=_-]*$ ]]; then
    echo "That does not look like a Cloudflare Tunnel token." >&2
    exit 1
fi

CLOUDFLARE_TUNNEL=false

if [ -n "$CLOUDFLARE_TUNNEL_TOKEN" ]; then
    CLOUDFLARE_TUNNEL=true
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "Run the installer as root." >&2
    exit 1
fi

# Everything the installer prints is also kept in a root-only log, since it
# includes the administrator password and is easy to lose from the terminal.
LOG=/root/swage-install.log
(umask 077 && touch "$LOG")
chmod 600 "$LOG"
echo "==> Swage install started $(date -u '+%Y-%m-%d %H:%M:%S UTC')" >> "$LOG"
exec > >(tee -a "$LOG") 2>&1
trap 'echo; echo "The install failed. The full output is in $LOG." >&2' ERR

echo "A copy of this output is saved to $LOG, readable by root only."

. /etc/os-release

if [ "$ID" != "ubuntu" ] || [ "$VERSION_ID" != "24.04" ]; then
    echo "Swage requires Ubuntu 24.04 (found $PRETTY_NAME)." >&2
    exit 1
fi

if [ -e "$APP_ROOT/current" ]; then
    echo "Swage is already installed. Update it with: sudo -u $APP_USER php $APP_ROOT/current/artisan swage:update" >&2
    exit 1
fi

# Hardening disables SSH password logins, so make sure a key gets you back in.
if ! grep -qE '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys 2> /dev/null; then
    echo "Add your SSH public key to /root/.ssh/authorized_keys first." >&2
    echo "The installer disables password logins and would otherwise lock you out." >&2
    exit 1
fi

read -rp "Domain Swage will be served from (e.g. swage.example.com): " DOMAIN
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    read -rp "Administrator email: " EMAIL
else
    read -rp "Email for Let's Encrypt notices: " EMAIL
fi

step() {
    echo
    echo "==> $1"
}

as_app() {
    sudo -u "$APP_USER" -H "$@"
}

# Through a tunnel the domain resolves to Cloudflare, never to this server.
if [ "$CLOUDFLARE_TUNNEL" = false ]; then
    PUBLIC_IP=$(curl -fsS4 https://api.ipify.org || true)
    DOMAIN_IP=$(getent ahostsv4 "$DOMAIN" | awk 'NR == 1 { print $1 }' || true)
fi

if [ "$CLOUDFLARE_TUNNEL" = false ] && [ -n "$PUBLIC_IP" ] && [ "$PUBLIC_IP" != "$DOMAIN_IP" ]; then
    echo
    echo "Warning: $DOMAIN resolves to '${DOMAIN_IP:-nothing}', but this server is $PUBLIC_IP."
    echo "The SSL certificate step will fail until DNS points here."
    read -rp "Press Enter to continue anyway, or Ctrl+C to stop..."
fi

# ---------------------------------------------------------------------------
step "Creating the $APP_USER user"
# ---------------------------------------------------------------------------

apt-get update -q
apt-get install -yq git openssh-client curl

if ! id "$APP_USER" > /dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$APP_USER"
fi

as_app mkdir -p "/home/$APP_USER/.ssh"
chmod 700 "/home/$APP_USER/.ssh"

# A public repository is cloned over HTTPS. A private fork is cloned over SSH with a read-only deploy key, which root
# keeps its own copy of, along with GitHub's host key, so host migrations never depend on files the application user
# can change.
if [[ "$REPOSITORY" =~ ^(git@|ssh://) ]]; then
    if [ ! -f "/home/$APP_USER/.ssh/id_ed25519" ]; then
        as_app ssh-keygen -t ed25519 -N "" -C "swage@$DOMAIN" -f "/home/$APP_USER/.ssh/id_ed25519" > /dev/null
    fi

    mkdir -p /root/.ssh
    chmod 700 /root/.ssh
    install -m 600 -o root -g root "/home/$APP_USER/.ssh/id_ed25519" /root/.ssh/swage_deploy_key
    ssh-keyscan -t ed25519,rsa github.com 2> /dev/null > /root/.ssh/known_hosts.github
    cat /root/.ssh/known_hosts.github >> /root/.ssh/known_hosts
    as_app tee -a "/home/$APP_USER/.ssh/known_hosts" < /root/.ssh/known_hosts.github > /dev/null
    rm /root/.ssh/known_hosts.github

    echo
    echo "Add this deploy key (read-only) to $REPOSITORY on GitHub:"
    if [[ "$REPOSITORY" =~ ^git@github\.com:(.+)\.git$ ]]; then
        echo "  https://github.com/${BASH_REMATCH[1]}/settings/keys/new"
    else
        echo "  Repository → Settings → Deploy keys → Add deploy key"
    fi
    echo
    cat "/home/$APP_USER/.ssh/id_ed25519.pub"
    echo
    read -rp "Press Enter once the deploy key has been added..."
fi

until as_app git ls-remote "$REPOSITORY" "refs/heads/$BRANCH" > /dev/null 2>&1; do
    read -rp "Unable to read $REPOSITORY. Check the repository (and its deploy key, for a private fork) and press Enter to retry..."
done

# Swage is released through version tags, which updates follow too. Install the newest one, or the newest commit on
# the branch while the repository has no version tags yet. Pre-releases, such as v2.0.0-beta, are left out.
REF=$(as_app git ls-remote --tags --refs "$REPOSITORY" | awk '{ sub("refs/tags/", "", $2); print $2 }' \
    | grep -E '^v?[0-9]+(\.[0-9]+){0,2}$' | sed 's/^v//' | sort -V | tail -n 1 || true)

if [ -n "$REF" ]; then
    REF=$(as_app git ls-remote --tags --refs "$REPOSITORY" | awk '{ sub("refs/tags/", "", $2); print $2 }' | grep -E "^v?${REF//./\\.}\$" | head -n 1)
    echo "Installing Swage $REF"
else
    REF=$BRANCH
    echo "No version tags yet, installing the newest commit on $BRANCH"
fi

# ---------------------------------------------------------------------------
step "Configuring the server with host migrations"
# ---------------------------------------------------------------------------

mkdir -p /etc/swage
chmod 755 /etc/swage

cat > /etc/swage/swage.conf <<EOF
REPOSITORY=$REPOSITORY
BRANCH=$BRANCH
DOMAIN=$DOMAIN
EMAIL=$EMAIL
APP_USER=$APP_USER
APP_ROOT=$APP_ROOT
PHP_VERSION=$PHP_VERSION
NODE_VERSION=$NODE_VERSION
CLOUDFLARE_TUNNEL=$CLOUDFLARE_TUNNEL
EOF
chmod 644 /etc/swage/swage.conf

# The token lets anyone run a connector for the tunnel, so it lives apart from the world-readable config.
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    (umask 077 && echo "TUNNEL_TOKEN=$CLOUDFLARE_TUNNEL_TOKEN" > /etc/swage/cloudflared.env)
fi

export GIT_SSH_COMMAND="ssh -i /root/.ssh/swage_deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

# A failed attempt leaves its checkout behind. Running the installer again
# fetches the newest release, and the host migrations it already applied are
# skipped, so it picks up where it stopped with any fixes released since.
if [ ! -d "$SOURCE/.git" ]; then
    git clone --quiet --depth 100 --branch "$BRANCH" "$REPOSITORY" "$SOURCE"
fi

git -C "$SOURCE" fetch --quiet --depth 100 origin "$REF"
COMMIT=$(git -C "$SOURCE" rev-parse 'FETCH_HEAD^{commit}')

unset GIT_SSH_COMMAND

install -m 755 -o root -g root "$SOURCE/resources/scripts/host/swage-host-migrate" /usr/local/sbin/swage-host-migrate

/usr/local/sbin/swage-host-migrate "$COMMIT"

# ---------------------------------------------------------------------------
step "Installing the first release"
# ---------------------------------------------------------------------------

RELEASE="$APP_ROOT/releases/$(date +%Y%m%d%H%M%S)"

as_app mkdir -p "$APP_ROOT/releases" \
    "$APP_ROOT/shared/storage/app/public" \
    "$APP_ROOT/shared/storage/framework/cache/data" \
    "$APP_ROOT/shared/storage/framework/sessions" \
    "$APP_ROOT/shared/storage/framework/views" \
    "$APP_ROOT/shared/storage/logs"

as_app git -c advice.detachedHead=false clone --depth 1 --branch "$REF" "$REPOSITORY" "$RELEASE"

as_app cp "$RELEASE/.env.example" "$APP_ROOT/shared/.env"
chmod 600 "$APP_ROOT/shared/.env"

set_env() {
    local file="$APP_ROOT/shared/.env"

    if grep -qE "^#? ?$1=" "$file"; then
        sed -i -E "s|^#? ?$1=.*|$1=$2|" "$file"
    else
        echo "$1=$2" >> "$file"
    fi
}

set_env APP_NAME Swage
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$DOMAIN"
set_env LOG_CHANNEL daily
set_env LOG_LEVEL warning
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE swage
set_env DB_USERNAME swage
set_env DB_PASSWORD "$(grep '^DB_PASSWORD=' /etc/swage/secrets.env | cut -d= -f2-)"
set_env CACHE_STORE redis
set_env SESSION_DRIVER redis
set_env SESSION_SECURE_COOKIE true
set_env QUEUE_CONNECTION redis
set_env REDIS_HOST 127.0.0.1
set_env REDIS_QUEUE_RETRY_AFTER 2000
set_env SWAGE_DEPLOYMENT_QUEUE deployments
set_env SWAGE_UPDATE_BRANCH "$BRANCH"
set_env SWAGE_PHP_FPM_SERVICE "php$PHP_VERSION-fpm"
set_env SWAGE_CERTIFICATE_EMAIL "$EMAIL"
set_env SWAGE_ADMIN_EMAIL "$EMAIL"
set_env SWAGE_REQUIRE_TWO_FACTOR true

# Behind a tunnel, Cloudflare Access may guard the public URL, so self-updates
# check the new release through nginx's loopback listener instead.
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    set_env SWAGE_HEALTH_CHECK_URL "http://127.0.0.1/up"
fi

as_app rm -rf "$RELEASE/storage"
as_app ln -s "$APP_ROOT/shared/storage" "$RELEASE/storage"
as_app ln -s "$APP_ROOT/shared/.env" "$RELEASE/.env"

cd "$RELEASE"
as_app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
as_app php artisan key:generate --force
as_app npm ci
as_app npm run build
as_app php artisan migrate --force
as_app php artisan optimize

# Registration is disabled. This creates the administrator account and prints its password.
as_app php artisan db:seed --force

as_app ln -sfn "$RELEASE" "$APP_ROOT/current"

systemctl reload php$PHP_VERSION-fpm
# The workers started before the first release existed, so they gave up.
supervisorctl restart 'swage-worker:*' 'swage-deployer:*'

echo
echo "==> Swage is installed at https://$DOMAIN"
echo
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    echo "SSH now only accepts keys, and only port 22 is open. Web traffic arrives through the Cloudflare Tunnel."
else
    echo "SSH now only accepts keys, and only ports 22, 80 and 443 are open."
fi
echo
echo "Next steps:"
echo "  1. Log in at https://$DOMAIN/login with the administrator password printed above,"
echo "     and set up two-factor authentication."
echo "  2. Install the GitHub or GitLab plugin under Plugins → System, configure it there,"
echo "     then connect your account under Settings → Source control."
echo "  3. Update Swage from the Updates page, or with:"
echo "     sudo -u $APP_USER php $APP_ROOT/current/artisan swage:update"
echo
echo "This output, including the administrator password, is saved to $LOG."
echo "Delete it once the password is stored somewhere safe: rm $LOG"
