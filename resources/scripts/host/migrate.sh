#!/usr/bin/env bash
#
# Runs every host migration that has not been applied yet, in filename order.
# Invoked by swage-host-migrate with the configuration already exported.
#
set -Eeuo pipefail

SOURCE=/opt/swage
HOST="$SOURCE/resources/scripts/host"
STATE=/etc/swage/host-migrations

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

touch "$STATE"
chmod 600 "$STATE"

applied=0

for file in "$HOST"/migrations/*.sh; do
    name=$(basename "$file" .sh)

    if grep -qxF "$name" "$STATE"; then
        continue
    fi

    echo
    echo "==> Host migration: $name"

    bash -Eeuo pipefail -c 'source "$1"; source "$2"' _ "$HOST/lib.sh" "$file"

    echo "$name" >> "$STATE"
    applied=$((applied + 1))
done

# Keep the installed runner and root-only tools in step with the repository.
# The runner has already handed control to this script, so replacing it here
# is safe.
for tool in swage-host-migrate swage-change-domain; do
    if ! cmp -s "$HOST/$tool" "/usr/local/sbin/$tool"; then
        install -m 755 -o root -g root "$HOST/$tool" "/usr/local/sbin/$tool"
        echo "Updated /usr/local/sbin/$tool"
    fi
done

if [ "$applied" -eq 0 ]; then
    echo "Host is up to date."
else
    echo
    echo "Applied $applied host migration(s)."
fi
