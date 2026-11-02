set -Eeuo pipefail

# Only lines holding exactly this key are dropped. The file is rewritten in place, so it keeps its owner and
# permissions. Sessions already signed in with the key stay open.
@include('scripts.ssh.partials.as-user', ['arguments' => [$key->data]])
set -Eeuo pipefail
[ -f "$1/authorized_keys" ] || exit 0
awk -v data="$2" '{ for (i = 1; i <= NF; i++) if ($i == data) next } { print }' "$1/authorized_keys" > "$1/authorized_keys.swage-new"
cat "$1/authorized_keys.swage-new" > "$1/authorized_keys"
rm -f "$1/authorized_keys.swage-new"
SWAGE_EOF
