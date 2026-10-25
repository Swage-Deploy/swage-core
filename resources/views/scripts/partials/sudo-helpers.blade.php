# Root-owned helpers are the only commands site users may run with sudo. They check their arguments
# themselves, because a sudoers wildcard would also match extra arguments such as "stop".
cat > /usr/local/sbin/swage-reload-php <<'SWAGE_EOF'
#!/usr/bin/env bash
# Reloads PHP-FPM for one PHP version: swage-reload-php 8.4
set -euo pipefail

if ! [[ "${1:-}" =~ ^[0-9]+\.[0-9]+$ ]] || [ "$#" -ne 1 ]; then
    echo "Usage: swage-reload-php <php version>" >&2
    exit 64
fi

exec /usr/sbin/service "php$1-fpm" reload
SWAGE_EOF

cat > /usr/local/sbin/swage-restart-daemon <<'SWAGE_EOF'
#!/usr/bin/env bash
# Restarts a Supervisor program Swage installed, but only one that runs as the user calling sudo: swage-restart-daemon 12
set -euo pipefail

if ! [[ "${1:-}" =~ ^[0-9]+$ ]] || [ "$#" -ne 1 ]; then
    echo "Usage: swage-restart-daemon <daemon id>" >&2
    exit 64
fi

CONFIG="/etc/supervisor/conf.d/daemon-$1.conf"

if [ -z "${SUDO_USER:-}" ] || [ ! -f "$CONFIG" ] || ! grep -qxF "user=$SUDO_USER" "$CONFIG"; then
    echo "daemon-$1 does not belong to ${SUDO_USER:-this user}." >&2
    exit 1
fi

exec /usr/bin/supervisorctl restart "daemon-$1:*"
SWAGE_EOF

chown root:root /usr/local/sbin/swage-reload-php /usr/local/sbin/swage-restart-daemon
chmod 755 /usr/local/sbin/swage-reload-php /usr/local/sbin/swage-restart-daemon
