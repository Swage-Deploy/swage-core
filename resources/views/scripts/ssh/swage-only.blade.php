set -Eeuo pipefail

SWAGE_ONLY_CONFIG={!! App\Servers\SshAccess::SWAGE_ONLY_CONFIG !!}
SWAGE_ONLY_KEYS={!! App\Servers\SshAccess::SWAGE_ONLY_KEYS !!}

rm -f "$SWAGE_ONLY_CONFIG.swage-old"
if [ -f "$SWAGE_ONLY_CONFIG" ]; then cp -p "$SWAGE_ONLY_CONFIG" "$SWAGE_ONLY_CONFIG.swage-old"; fi

restore() {
    if [ -f "$SWAGE_ONLY_CONFIG.swage-old" ]; then mv "$SWAGE_ONLY_CONFIG.swage-old" "$SWAGE_ONLY_CONFIG"; else rm -f "$SWAGE_ONLY_CONFIG"; fi
}

@if ($swageOnly)
# sshd checks only this root-owned file, which holds nothing but Swage's key, and lets only root in. Each user's own
# authorized_keys is left as it is, for when SSH is turned back on.
install -d -m 755 -o root -g root "$(dirname "$SWAGE_ONLY_KEYS")"
printf '%s\n' '{!! $panelKey->type !!} {!! $panelKey->data !!} swage' > "$SWAGE_ONLY_KEYS"
chown root:root "$SWAGE_ONLY_KEYS"
chmod 644 "$SWAGE_ONLY_KEYS"

cat > "$SWAGE_ONLY_CONFIG" <<'SWAGE_EOF'
# Managed by Swage: only Swage may sign in over SSH, as root with its own key.
AllowUsers root
AuthorizedKeysFile {!! App\Servers\SshAccess::SWAGE_ONLY_KEYS !!}
SWAGE_EOF
chmod 644 "$SWAGE_ONLY_CONFIG"
@else
rm -f "$SWAGE_ONLY_CONFIG"
@endif

# Ubuntu 24.04 starts sshd on demand, so its runtime directory may not exist yet, and sshd -t refuses to check the
# config without it.
install -d -m 755 /run/sshd
if ! sshd -t; then
    restore
    echo "sshd rejected the new config, so the previous one was kept." >&2
    exit 1
fi
@if ($swageOnly)

# sshd uses the first AuthorizedKeysFile it reads, so make sure no earlier config sets its own, which would let
# other keys in.
if [ "$(sshd -T | awk '$1 == "authorizedkeysfile" { $1 = ""; print substr($0, 2) }')" != "$SWAGE_ONLY_KEYS" ]; then
    restore
    echo "Another sshd config file sets AuthorizedKeysFile before Swage's, so SSH was left as it was. Find it with:" >&2
    echo "  grep -rni AuthorizedKeysFile /etc/ssh/sshd_config /etc/ssh/sshd_config.d/" >&2
    exit 1
fi
@endif
rm -f "$SWAGE_ONLY_CONFIG.swage-old"
systemctl try-reload-or-restart ssh
@if ($swageOnly)

# End the site users' open SSH sessions. Their sites keep running. Open root sessions stay open until they end.
@foreach ($siteUsers as $siteUser)
pkill -KILL -u '{!! $siteUser !!}' sshd || true
@endforeach
@else
rm -f "$SWAGE_ONLY_KEYS"
@endif
