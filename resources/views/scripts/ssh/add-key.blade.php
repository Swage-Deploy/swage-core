set -Eeuo pipefail

@include('scripts.ssh.partials.as-user', ['arguments' => [$key->data, "{$key->type} {$key->data} {$key->comment}"]])
set -Eeuo pipefail
umask 077
mkdir -p "$1"
chmod 700 "$1"
touch "$1/authorized_keys"
chmod 600 "$1/authorized_keys"
if ! awk -v data="$2" '{ for (i = 1; i <= NF; i++) if ($i == data) found = 1 } END { exit !found }' "$1/authorized_keys"; then
    # Start on a new line when the file doesn't end with one.
    if [ -s "$1/authorized_keys" ] && [ -n "$(tail -c 1 "$1/authorized_keys")" ]; then
        echo >> "$1/authorized_keys"
    fi
    printf '%s\n' "$3" >> "$1/authorized_keys"
fi
SWAGE_EOF
