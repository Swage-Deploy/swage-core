set -Eeuo pipefail

@if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $publicKey))
# Swage no longer manages this server, so its key stops granting access. The current session is unaffected.
for file in /root/.ssh/authorized_keys /home/{!! $user !!}/.ssh/authorized_keys; do
    if [ -f "$file" ]; then
        { grep -vF '{!! $publicKey !!}' "$file" || true; } > "$file.swage-new"
        cat "$file.swage-new" > "$file"
        rm -f "$file.swage-new"
    fi
done

echo "Removed Swage's SSH key."
@else
echo "Swage's SSH key could not be identified." >&2
exit 1
@endif
