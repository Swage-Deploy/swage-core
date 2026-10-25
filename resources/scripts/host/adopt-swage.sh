#!/usr/bin/env bash
#
# Brings a Swage install created before host migrations existed under their
# management. Run it once, as root, on the existing Swage server:
#
#   scp resources/scripts/host/adopt-swage.sh root@your-server:
#   ssh -t root@your-server bash adopt-swage.sh
#
# It reads the existing install's settings, keeps its database password, sets
# up the root-owned host migration runner and applies every host migration,
# which hardens the server. Swage keeps running throughout.
#
set -Eeuo pipefail

APP_USER=swage
APP_ROOT="/home/$APP_USER/app"
SOURCE=/opt/swage
ENV_FILE="$APP_ROOT/shared/.env"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run the adoption script as root." >&2
    exit 1
fi

# Re-running after a failed attempt is safe: applied migrations are skipped
# and the rest pick up where the last attempt stopped.
if [ -f /etc/swage/adopted ]; then
    echo "This install already uses host migrations. Update Swage normally." >&2
    exit 1
fi

if [ ! -e "$APP_ROOT/current" ] || [ ! -f "$ENV_FILE" ]; then
    echo "No Swage install found at $APP_ROOT." >&2
    exit 1
fi

if ! grep -qE '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys 2> /dev/null; then
    echo "Add your SSH public key to /root/.ssh/authorized_keys first." >&2
    echo "Hardening disables password logins and would otherwise lock you out." >&2
    exit 1
fi

env_value() {
    grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'
}

APP_URL=$(env_value APP_URL)
DOMAIN=${APP_URL#*://}
DOMAIN=${DOMAIN%%/*}
BRANCH=$(env_value SWAGE_UPDATE_BRANCH)
BRANCH=${BRANCH:-main}
EMAIL=$(env_value SWAGE_CERTIFICATE_EMAIL)
DB_PASSWORD=$(env_value DB_PASSWORD)
FPM_SERVICE=$(env_value SWAGE_PHP_FPM_SERVICE)
PHP_VERSION=$(echo "${FPM_SERVICE:-php8.5-fpm}" | sed -E 's/^php([0-9.]+)-fpm$/\1/')
REPOSITORY=$(sudo -u "$APP_USER" git -C "$APP_ROOT/current" remote get-url origin)

if [ -z "$EMAIL" ]; then
    read -rp "Email for Let's Encrypt notices: " EMAIL
fi

echo "Adopting the Swage install at $APP_ROOT:"
echo "  Domain:      $DOMAIN"
echo "  Repository:  $REPOSITORY ($BRANCH)"
echo "  PHP:         $PHP_VERSION"
echo "  Email:       $EMAIL"
echo
echo "This applies every host migration: SSH becomes key-only, the firewall is"
echo "tightened and fail2ban, unattended upgrades and kernel hardening are enabled."
read -rp "Press Enter to continue, or Ctrl+C to stop..."

mkdir -p /etc/swage
chmod 755 /etc/swage

cat > /etc/swage/swage.conf <<CONF
REPOSITORY=$REPOSITORY
BRANCH=$BRANCH
DOMAIN=$DOMAIN
EMAIL=$EMAIL
APP_USER=$APP_USER
APP_ROOT=$APP_ROOT
PHP_VERSION=$PHP_VERSION
NODE_VERSION=22
CONF
chmod 644 /etc/swage/swage.conf

# Keep the database password the app already uses, so the MySQL migration
# does not generate a new one and lock Swage out of its database.
touch /etc/swage/secrets.env
chmod 600 /etc/swage/secrets.env
grep -q '^DB_PASSWORD=' /etc/swage/secrets.env || echo "DB_PASSWORD=$DB_PASSWORD" >> /etc/swage/secrets.env

mkdir -p /root/.ssh
chmod 700 /root/.ssh
install -m 600 -o root -g root "/home/$APP_USER/.ssh/id_ed25519" /root/.ssh/swage_deploy_key
ssh-keygen -F github.com -f /root/.ssh/known_hosts > /dev/null 2>&1 \
    || ssh-keyscan -t ed25519,rsa github.com 2> /dev/null >> /root/.ssh/known_hosts

export GIT_SSH_COMMAND="ssh -i /root/.ssh/swage_deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

if [ ! -d "$SOURCE/.git" ]; then
    git clone --quiet --depth 100 --branch "$BRANCH" "$REPOSITORY" "$SOURCE"
else
    git -C "$SOURCE" fetch --quiet --depth 100 origin "$BRANCH"
    git -C "$SOURCE" checkout --quiet --force --detach FETCH_HEAD
fi

install -m 755 -o root -g root "$SOURCE/resources/scripts/host/swage-host-migrate" /usr/local/sbin/swage-host-migrate

/usr/local/sbin/swage-host-migrate "$(git -C "$SOURCE" rev-parse HEAD)"

# Settings added to the installer since this server was set up.
if ! grep -q '^SESSION_SECURE_COOKIE=' "$ENV_FILE"; then
    echo "SESSION_SECURE_COOKIE=true" >> "$ENV_FILE"
fi

sudo -u "$APP_USER" -H php "$APP_ROOT/current/artisan" optimize
sudo -u "$APP_USER" -H php "$APP_ROOT/current/artisan" queue:restart
systemctl reload "php$PHP_VERSION-fpm"

touch /etc/swage/adopted

echo
echo "==> Swage is now managed by host migrations"
echo
echo "SSH now only accepts keys. Check you can still log in from a new terminal"
echo "before closing this session."
echo
echo "Update Swage from the Updates page as usual. Future server changes are"
echo "applied automatically during updates."
