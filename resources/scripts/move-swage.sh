#!/usr/bin/env bash
#
# Moves Swage from the server it runs on now to this one. As root on a fresh Ubuntu 24.04 server:
#
#   apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/move-swage.sh -o move-swage.sh && bash move-swage.sh
#
# Before anything changes, it checks it can log in to every server Swage manages, the way Swage does. Then it
# connects to the old host as root over SSH and pulls everything across: Swage's configuration and secrets, its
# certificate, the running release with its plugins, the shared storage, the database and Redis, which holds queued
# jobs and sessions. The old host is put into maintenance mode, with its workers and scheduler stopped, so only one
# panel ever acts on the servers. Then every managed server is checked from here, and fail2ban on each one is told to
# leave this host's address alone instead of the old one's.
#
# Nothing on the managed servers needs their SSH keys changed: Swage's keys are in its database, which moves with it.
# Swage keeps its domain, so point the domain's DNS at this server afterwards, unless it is served through a
# Cloudflare Tunnel, which moves with it too.
#
# Pass the old host as --from, and --port when its SSH server is not on port 22, or you are asked for them:
#
#   bash move-swage.sh --from old.example.com --port 22
#
# If the move stops part way, fix the problem and run the script again: each step is safe to repeat.
#
set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

APP_USER=swage
APP_ROOT="/home/$APP_USER/app"
SOURCE=/opt/swage
KEY=/root/.ssh/swage_move
KNOWN_HOSTS=/root/.ssh/known_hosts.swage-move
OLD_HOST=
OLD_PORT=22

while [ "$#" -gt 0 ]; do
    case "$1" in
        --from=*) OLD_HOST=${1#*=} ;;
        --port=*) OLD_PORT=${1#*=} ;;
        --from | --port)
            if [ "$#" -lt 2 ]; then
                echo "$1 needs a value." >&2
                exit 1
            fi
            [ "$1" = --from ] && OLD_HOST=$2 || OLD_PORT=$2
            shift
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
    shift
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run the move as root." >&2
    exit 1
fi

LOG=/root/swage-move.log
(umask 077 && touch "$LOG")
chmod 600 "$LOG"
echo "==> Swage move started $(date -u '+%Y-%m-%d %H:%M:%S UTC')" >> "$LOG"
exec > >(tee -a "$LOG") 2>&1

OLD_STOPPED=false

failed() {
    echo
    echo "The move stopped. The full output is in $LOG." >&2
    echo "Fix the problem and run this script again to carry on from where it stopped." >&2

    if [ "$OLD_STOPPED" = true ]; then
        echo >&2
        echo "The old panel is in maintenance mode, with its workers and scheduler stopped. To give up on the move" >&2
        echo "and start it again, run this on the old host:" >&2
        echo "  bash /root/swage-moved/restore.sh" >&2

        if systemctl is-enabled --quiet swage-tunnel 2> /dev/null; then
            echo "and stop this server's Cloudflare Tunnel connector, so the tunnel only reaches the old host:" >&2
            echo "  systemctl disable --now swage-tunnel" >&2
        fi
    fi
}
trap failed ERR

# The servers' keys are decrypted for the check that this server can reach them, so they never outlive the script.
trap 'rm -rf /root/swage-move-checks.*' EXIT

echo "A copy of this output is saved to $LOG, readable by root only."

. /etc/os-release

if [ "$ID" != "ubuntu" ] || [ "$VERSION_ID" != "24.04" ]; then
    echo "Swage requires Ubuntu 24.04 (found $PRETTY_NAME)." >&2
    exit 1
fi

if [ -e "$APP_ROOT/current" ]; then
    echo "Swage is already installed on this server." >&2
    exit 1
fi

# The host migrations disable SSH password logins here too, so make sure a key gets you back in.
if ! grep -qE '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys 2> /dev/null; then
    echo "Add your SSH public key to /root/.ssh/authorized_keys first." >&2
    echo "Moving Swage disables password logins on this server and would otherwise lock you out." >&2
    exit 1
fi

step() {
    echo
    echo "==> $1"
}

as_app() {
    sudo -u "$APP_USER" -H "$@"
}

# Run a command on the old host as root. Each argument is passed to its shell as it is, so quote values in them.
old() {
    ssh -i "$KEY" -p "$OLD_PORT" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 \
        -o ServerAliveInterval=30 -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$KNOWN_HOSTS" \
        -o Compression=yes "root@$OLD_HOST" "$@"
}

while [ -z "$OLD_HOST" ]; do
    read -rp "Address of the server Swage runs on now: " OLD_HOST
done

if ! [[ "$OLD_HOST" =~ ^[A-Za-z0-9.:-]+$ ]]; then
    echo "$OLD_HOST does not look like a host name or IP address." >&2
    exit 1
fi

if ! [[ "$OLD_PORT" =~ ^[0-9]+$ ]] || [ "$OLD_PORT" -lt 1 ] || [ "$OLD_PORT" -gt 65535 ]; then
    echo "$OLD_PORT is not a port number." >&2
    exit 1
fi

apt-get update -q
apt-get install -yq git openssh-client curl

NEW_ADDRESS=$(curl -fsS4 https://api.ipify.org || hostname -I | awk '{ print $1 }')

# ---------------------------------------------------------------------------
step "Connecting to the old host"
# ---------------------------------------------------------------------------

mkdir -p /root/.ssh
chmod 700 /root/.ssh

if [ ! -f "$KEY" ]; then
    ssh-keygen -t ed25519 -N "" -C "swage-move@$(hostname)" -f "$KEY" > /dev/null
fi

# The old host's key is shown for you to check, rather than trusted on first sight, since the move hands it nothing
# but takes its database and secrets on trust.
if ! ssh-keygen -F "[$OLD_HOST]:$OLD_PORT" -f "$KNOWN_HOSTS" > /dev/null 2>&1 && ! ssh-keygen -F "$OLD_HOST" -f "$KNOWN_HOSTS" > /dev/null 2>&1; then
    until scanned=$(ssh-keyscan -T 15 -p "$OLD_PORT" -t ed25519 "$OLD_HOST" 2> /dev/null) && [ -n "$scanned" ]; do
        echo
        echo "Unable to reach an SSH server at $OLD_HOST on port $OLD_PORT. Check the address and port, and that the old"
        echo "host's firewall lets this server ($NEW_ADDRESS) in, such as with: ufw allow from $NEW_ADDRESS to any port $OLD_PORT"
        read -rp "Press Enter to try again, or Ctrl+C to stop..."
    done

    echo
    echo "The old host's SSH key fingerprint is:"
    printf '%s\n' "$scanned" | ssh-keygen -lf - | awk '{ print "  " $2 }'
    echo "Check it matches what this shows on the old host:"
    echo "  ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub"
    read -rp "Type yes if it matches: " answer

    if [ "$answer" != yes ]; then
        echo "The fingerprint was not confirmed, so nothing was moved." >&2
        exit 1
    fi

    (umask 077 && printf '%s\n' "$scanned" > "$KNOWN_HOSTS")
fi

PUBLIC_KEY=$(cat "$KEY.pub")

if ! old true 2> /dev/null; then
    echo
    echo "This server needs to log in to the old host as root. On the old host, as root, run:"
    echo
    echo "  echo '$PUBLIC_KEY' >> /root/.ssh/authorized_keys"
    echo
    echo "The key is removed again once the move is finished."
    read -rp "Press Enter once it is added..."
fi

until error=$(old true 2>&1); do
    echo
    echo "Unable to log in to $OLD_HOST as root:"
    echo "  $error"
    echo

    case "$error" in
        *"Permission denied"*)
            echo "The old host turned the login down. On the old host, check:"
            echo
            echo "  1. The key above is in /root/.ssh/authorized_keys, on a line of its own."
            echo "  2. Root may log in with a key. This shows the settings SSH is using:"
            echo "       sshd -T | grep -Ei '^(permitrootlogin|pubkeyauthentication|authenticationmethods|allowusers|allowgroups|denyusers|denygroups) '"
            echo "     permitrootlogin must be prohibit-password, without-password or yes, not no or forced-commands-only."
            echo "     pubkeyauthentication must be yes. allowusers, if set, must include root, and denyusers must not."
            echo "     authenticationmethods, if set, must accept publickey on its own."
            echo "  3. To change one, find where it is set:"
            echo "       grep -rniE '^\\s*(PermitRootLogin|PubkeyAuthentication|AuthenticationMethods|AllowUsers|DenyUsers)' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/"
            echo "     For example, change PermitRootLogin no to PermitRootLogin prohibit-password. SSH uses the first value"
            echo "     it reads, and files in sshd_config.d are read in name order before the rest of sshd_config."
            echo "     Check the config and apply it with:"
            echo "       sshd -t && systemctl reload ssh"
            echo "     You can put the setting back once the move is finished."
            ;;
        *"Connection refused"*)
            echo "Nothing is accepting SSH connections on port $OLD_PORT of the old host. Check the port with:"
            echo "  sshd -T | grep -i '^port '"
            echo "and run this script again with --port if it is another one."
            ;;
        *"timed out"* | *"No route to host"* | *"Connection closed"* | *"Connection reset"*)
            echo "The old host did not answer. Its firewall or fail2ban may be keeping this server out. On the old host:"
            echo "  ufw allow from $NEW_ADDRESS to any port $OLD_PORT"
            echo "  fail2ban-client unban $NEW_ADDRESS"
            ;;
        *"Host key verification failed"* | *"REMOTE HOST IDENTIFICATION HAS CHANGED"*)
            echo "The old host's SSH key is not the one you confirmed. If its key really changed, delete $KNOWN_HOSTS"
            echo "and run this script again to check the new one."
            ;;
    esac

    echo
    read -rp "Press Enter to try again, or Ctrl+C to stop..."
done

echo "Logged in to $OLD_HOST as root."

# ---------------------------------------------------------------------------
step "Checking the old host"
# ---------------------------------------------------------------------------

if ! old "test -f /etc/swage/swage.conf && test -L $APP_ROOT/current"; then
    echo "$OLD_HOST does not look like a Swage host: it has no /etc/swage/swage.conf or $APP_ROOT/current." >&2
    exit 1
fi

CONFIG=$(old cat /etc/swage/swage.conf)

config() {
    printf '%s\n' "$CONFIG" | sed -n "s/^$1=//p" | tail -n 1
}

DOMAIN=$(config DOMAIN)
REPOSITORY=$(config REPOSITORY)
BRANCH=$(config BRANCH)
PHP_VERSION=$(config PHP_VERSION)
CLOUDFLARE_TUNNEL=$(config CLOUDFLARE_TUNNEL)
CLOUDFLARE_TUNNEL=${CLOUDFLARE_TUNNEL:-false}

HOST_COMMIT=$(old cat /etc/swage/host-commit | tr -d '[:space:]')
RELEASE=$(old readlink -f "$APP_ROOT/current")

if ! [[ "$HOST_COMMIT" =~ ^[0-9a-f]{40}$ ]]; then
    echo "The old host has no host migrations recorded in /etc/swage/host-commit, so it cannot be moved." >&2
    exit 1
fi

if ! [[ "$RELEASE" =~ ^$APP_ROOT/releases/[0-9]+$ ]]; then
    echo "The old host's live release, $RELEASE, is not in $APP_ROOT/releases." >&2
    exit 1
fi

RELEASE_NAME=${RELEASE##*/}

# The release moves as it is, and its swage:host-moved command updates the servers once it runs here.
if ! old "test -f $RELEASE/app/Console/Commands/SwageHostMoved.php"; then
    echo "The old host's Swage is too old to move: it has no swage:host-moved command. Update it from its Updates" >&2
    echo "page, then run this script again." >&2
    exit 1
fi
VERSION=$(old "sudo -u $APP_USER git -C $RELEASE describe --tags --always 2> /dev/null" || echo unknown)
SIZE=$(old "du -sh $APP_ROOT/shared $RELEASE /var/lib/mysql/swage 2> /dev/null | awk '{ print \$1 }' | paste -sd ' '" || true)

# ---------------------------------------------------------------------------
step "Checking this server can reach the servers Swage manages"
# ---------------------------------------------------------------------------

# Before anything changes, each server is logged in to from here the way Swage does it: as root, with the server's own
# key, and only when the server shows the host key Swage pinned for it. The old panel decrypts the keys, since they
# are encrypted with its app key. They are kept in a root-only directory for the check and deleted afterwards.
CHECKS=$(mktemp -d /root/swage-move-checks.XXXXXX)
chmod 700 "$CHECKS"

old "cd $APP_ROOT/current && sudo -u $APP_USER php" > "$CHECKS/servers" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Server::query()->where('status', 'active')->orderBy('name')->get() as $server) {
    // A separator that is not whitespace, so read keeps an empty host key as its own field.
    echo implode('|', [
        base64_encode($server->name),
        $server->ip_address,
        $server->ssh_port,
        base64_encode((string) $server->host_key),
        base64_encode((string) $server->ssh_private_key),
    ]), "\n";
}
PHP

check_servers() {
    local name address port host_key private_key error index=0
    UNREACHABLE=()

    while IFS='|' read -r name address port host_key private_key; do
        index=$((index + 1))
        name=$(printf '%s' "$name" | base64 -d)

        if ! [[ "$address" =~ ^[0-9A-Fa-f:.]+$ && "$port" =~ ^[0-9]+$ ]]; then
            UNREACHABLE+=("$name: Swage has no usable address for it ($address port $port).")
            continue
        fi

        host_key=$(printf '%s' "$host_key" | base64 -d)

        if [ -z "$host_key" ]; then
            echo "  $name: skipped, since Swage has no host key pinned for it and never connects to it."
            continue
        fi

        printf '%s' "$private_key" | base64 -d > "$CHECKS/key-$index"
        chmod 600 "$CHECKS/key-$index"
        printf '[%s]:%s %s\n' "$address" "$port" "$host_key" > "$CHECKS/known-$index"

        if error=$(ssh -n -i "$CHECKS/key-$index" -p "$port" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 \
            -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$CHECKS/known-$index" -o HostKeyAlgorithms=ssh-ed25519 \
            -o HostKeyAlias="[$address]:$port" "root@$address" true 2>&1); then
            echo "  $name ($address): reachable."
        else
            UNREACHABLE+=("$name ($address, port $port): $(printf '%s' "$error" | tail -n 1)")
        fi
    done < "$CHECKS/servers"
}

while true; do
    check_servers

    if [ "${#UNREACHABLE[@]}" -eq 0 ]; then
        echo "Every server Swage manages can be reached from this server."
        break
    fi

    echo
    echo "These servers cannot be reached from this server ($NEW_ADDRESS), so Swage could not manage them after the move:"
    printf '  %s\n' "${UNREACHABLE[@]}"
    echo
    echo "Swage connects to each one as root on its SSH port. If one times out, or refuses the connection, its firewall"
    echo "or fail2ban may keep this server out, or it may only be reachable from the old host's network. On that server:"
    echo "  ufw status numbered          a rule may only let the old host in, or deny $NEW_ADDRESS"
    echo "  fail2ban-client banned       $NEW_ADDRESS may be banned; lift it with fail2ban-client unban $NEW_ADDRESS"
    echo "If it turns the login down, check sshd lets root log in with a key, as above. If its host key does not match,"
    echo "the server changed since Swage pinned its key."
    echo
    read -rp "Press Enter to check again, type skip to move anyway, or press Ctrl+C to stop: " answer

    if [ "$answer" = skip ]; then
        break
    fi
done

rm -rf "$CHECKS"

# ---------------------------------------------------------------------------
step "Copying Swage's configuration and source"
# ---------------------------------------------------------------------------

mkdir -p /etc/swage
chmod 755 /etc/swage

old 'cd /etc/swage && tar -cf - swage.conf secrets.env $(ls cloudflared.env 2> /dev/null)' | tar -C /etc/swage -xpf -
chmod 644 /etc/swage/swage.conf
chmod 600 /etc/swage/secrets.env
[ -f /etc/swage/cloudflared.env ] && chmod 600 /etc/swage/cloudflared.env

if old 'test -f /root/.ssh/swage_deploy_key'; then
    old 'cat /root/.ssh/swage_deploy_key' > /root/.ssh/swage_deploy_key.tmp
    install -m 600 -o root -g root /root/.ssh/swage_deploy_key.tmp /root/.ssh/swage_deploy_key
    rm -f /root/.ssh/swage_deploy_key.tmp
fi

# A private fork is cloned over SSH, and checked against the host keys the old host already trusted.
if [[ "$REPOSITORY" =~ ^(git@|ssh://) ]]; then
    host=${REPOSITORY#ssh://}
    host=${host#*@}
    host=${host%%[:/]*}

    if ! ssh-keygen -F "$host" -f /root/.ssh/known_hosts > /dev/null 2>&1; then
        old "ssh-keygen -F '$host' -f /root/.ssh/known_hosts" | grep -v '^#' >> /root/.ssh/known_hosts || true
    fi
fi

if [ "$CLOUDFLARE_TUNNEL" != true ] && old 'test -d /etc/letsencrypt'; then
    echo "Copying the certificate for $DOMAIN"
    old 'tar -C /etc -cpf - letsencrypt' | tar -C /etc -xpf -
fi

if ! id "$APP_USER" > /dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$APP_USER"
fi

# The swage user's own key and known hosts clone a private fork during updates. Its authorized keys stay behind.
old "tar -C /home/$APP_USER --exclude=.ssh/authorized_keys -cpf - .ssh 2> /dev/null" | tar -C "/home/$APP_USER" -xpf - || true
install -d -m 700 -o "$APP_USER" -g "$APP_USER" "/home/$APP_USER/.ssh"

export GIT_SSH_COMMAND="ssh -i /root/.ssh/swage_deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

if [ ! -d "$SOURCE/.git" ]; then
    git clone --quiet --depth 100 --branch "$BRANCH" "$REPOSITORY" "$SOURCE"
fi

git -C "$SOURCE" fetch --quiet --depth 100 origin "$BRANCH"

unset GIT_SSH_COMMAND

# Host migrations only fetch the newest 100 commits on the branch to check the commit they are given is on it, so an
# old host further behind than that has to be updated first.
if ! git -C "$SOURCE" rev-list FETCH_HEAD | grep -qxF "$HOST_COMMIT"; then
    echo "The old host's Swage is too far behind the newest release to move. Update it from its Updates page," >&2
    echo "then run this script again." >&2
    exit 1
fi

echo
echo "Swage $VERSION at https://$DOMAIN"
echo "Moving from $OLD_HOST to this server ($NEW_ADDRESS)."
echo "Storage, release and database: ${SIZE:-unknown size}"
echo
echo "From now on the old panel is put into maintenance mode, and its workers and scheduler are stopped, so only"
echo "one panel ever acts on your servers. Running deployments are allowed to finish first, for up to 30 minutes."
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    echo "The old host's Cloudflare Tunnel connector is stopped, and this server's takes over."
fi
echo
read -rp "Type move to continue: " answer

if [ "$answer" != move ]; then
    echo "Nothing was changed." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
step "Stopping the old panel"
# ---------------------------------------------------------------------------

# The changed files are kept in /root/swage-moved with a script that puts them back, in case the move is given up.
old bash -s <<'REMOTE'
set -euo pipefail

mkdir -p /root/swage-moved
chmod 700 /root/swage-moved

cat > /root/swage-moved/restore.sh <<'RESTORE'
#!/usr/bin/env bash
# Starts this panel again after a move to another server was given up. Don't run it once the new host is live:
# two panels would then act on the same servers.
set -euo pipefail

[ -f /root/swage-moved/cron ] && mv /root/swage-moved/cron /etc/cron.d/swage
[ -f /root/swage-moved/swage-worker.conf ] && mv /root/swage-moved/swage-worker.conf /etc/supervisor/conf.d/swage-worker.conf
supervisorctl update

if [ -f /root/swage-moved/tunnel ]; then
    systemctl enable --now swage-tunnel
    rm -f /root/swage-moved/tunnel
fi

sudo -u swage -H php /home/swage/app/current/artisan up
echo "Swage is running on this host again."
RESTORE
chmod 700 /root/swage-moved/restore.sh

sudo -u swage -H php /home/swage/app/current/artisan down --retry=60 || true

[ -f /etc/cron.d/swage ] && mv /etc/cron.d/swage /root/swage-moved/cron

if [ -f /etc/supervisor/conf.d/swage-worker.conf ]; then
    mv /etc/supervisor/conf.d/swage-worker.conf /root/swage-moved/swage-worker.conf
    echo "Waiting for the workers to finish their jobs..."
    supervisorctl update
fi

echo "The old panel is in maintenance mode, with its workers and scheduler stopped."
REMOTE

OLD_STOPPED=true

# ---------------------------------------------------------------------------
step "Configuring this server with host migrations"
# ---------------------------------------------------------------------------

# Both connectors would share the tunnel's traffic, and the old panel is down, so the old one stops before this
# server's starts.
if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    old 'if systemctl is-enabled --quiet swage-tunnel 2> /dev/null; then touch /root/swage-moved/tunnel; systemctl disable --now swage-tunnel; fi'
    echo "Stopped the old host's Cloudflare Tunnel connector."
fi

install -m 755 -o root -g root "$SOURCE/resources/scripts/host/swage-host-migrate" /usr/local/sbin/swage-host-migrate

# The same migrations the old host had, so this server is set up exactly as it was.
/usr/local/sbin/swage-host-migrate "$HOST_COMMIT"

# ---------------------------------------------------------------------------
step "Copying the release and storage"
# ---------------------------------------------------------------------------

# The release is copied as it was built, with its dependencies, assets and plugins, so it needs no rebuilding.
as_app mkdir -p "$APP_ROOT/releases"
old "tar -C $APP_ROOT -cpf - shared releases/$RELEASE_NAME" | tar -C "$APP_ROOT" -xpf -
chown -R "$APP_USER:$APP_USER" "$APP_ROOT"
chmod 600 "$APP_ROOT/shared/.env"

# ---------------------------------------------------------------------------
step "Copying the database"
# ---------------------------------------------------------------------------

old 'mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 swage' \
    | mysql --default-character-set=utf8mb4 swage
echo "Copied the swage database."

# ---------------------------------------------------------------------------
step "Copying Redis"
# ---------------------------------------------------------------------------

# Redis holds queued jobs, including delayed ones, and sessions, so nobody has to log in again.
REDIS_DUMP=$(mktemp)
old 'redis-cli SAVE > /dev/null && cat "$(redis-cli --raw CONFIG GET dir | tail -n 1)/$(redis-cli --raw CONFIG GET dbfilename | tail -n 1)"' > "$REDIS_DUMP"
REDIS_FILE="$(redis-cli --raw CONFIG GET dir | tail -n 1)/$(redis-cli --raw CONFIG GET dbfilename | tail -n 1)"
systemctl stop redis-server
install -m 660 -o redis -g redis "$REDIS_DUMP" "$REDIS_FILE"
rm -f "$REDIS_DUMP"
systemctl start redis-server
echo "Copied Redis."

# ---------------------------------------------------------------------------
step "Starting Swage here"
# ---------------------------------------------------------------------------

as_app ln -sfn "$APP_ROOT/releases/$RELEASE_NAME" "$APP_ROOT/current"

cd "$APP_ROOT/current"
as_app php artisan migrate --force
as_app php artisan optimize
systemctl reload "php$PHP_VERSION-fpm"
supervisorctl restart 'swage-worker:*' 'swage-deployer:*'
as_app php artisan up

# ---------------------------------------------------------------------------
step "Updating the servers Swage manages"
# ---------------------------------------------------------------------------

# The addresses the old host connected to servers from, so fail2ban stops leaving them alone.
OLD_ADDRESSES=$(old 'curl -fsS4 --max-time 10 https://api.ipify.org; echo; hostname -I' | tr -s '[:space:]' ' ' || true)

if ! as_app php artisan swage:host-moved --from="$OLD_ADDRESSES"; then
    echo
    echo "Some servers could not be reached from this server. Swage is running here, so check them from the panel."
    echo "Once they can be reached, run this again to update them:"
    echo "  sudo -u $APP_USER php $APP_ROOT/current/artisan swage:host-moved --from=\"$OLD_ADDRESSES\""
fi

# ---------------------------------------------------------------------------
step "Removing this server's key from the old host"
# ---------------------------------------------------------------------------

old "grep -vxF '$PUBLIC_KEY' /root/.ssh/authorized_keys > /root/.ssh/authorized_keys.swage-move; cat /root/.ssh/authorized_keys.swage-move > /root/.ssh/authorized_keys; rm -f /root/.ssh/authorized_keys.swage-move"
rm -f "$KEY" "$KEY.pub" "$KNOWN_HOSTS"
echo "Removed."

trap - ERR

echo
echo "==> Swage has moved to this server"
echo

if [ "$CLOUDFLARE_TUNNEL" = true ]; then
    echo "https://$DOMAIN is served from here through the Cloudflare Tunnel."
else
    DOMAIN_ADDRESS=$(getent ahostsv4 "$DOMAIN" | awk 'NR == 1 { print $1 }' || true)

    if [ "$DOMAIN_ADDRESS" = "$NEW_ADDRESS" ]; then
        echo "$DOMAIN already points here."
    else
        echo "Point $DOMAIN's DNS at this server: an A record for $NEW_ADDRESS, in place of ${DOMAIN_ADDRESS:-the old one}."
        echo "Until it does, visitors and webhooks reach the old host, which only shows that Swage is down for"
        echo "maintenance, and the certificate cannot be renewed here."
    fi
fi

echo
echo "The old host is left in maintenance mode, with its workers and scheduler stopped. Keep it until you are happy"
echo "with this server, then shut it down. Don't run /root/swage-moved/restore.sh on it now: two panels would then"
echo "act on the same servers."
echo
echo "If you changed the old host's SSH settings for the move, you can put them back."
echo
echo "This output is saved to $LOG."
