{{-- Add $sshUser to, or with $enabled take it off, the users Swage's sshd drop-in refuses. --}}
SSH_CONFIG={!! App\Servers\SshAccess::CONFIG !!}
SSH_DENIED_BEFORE=$(awk '$1 == "DenyUsers" { for (i = 2; i <= NF; i++) print $i }' "$SSH_CONFIG" 2> /dev/null | sort -u | xargs || true)
@if ($enabled)
SSH_DENIED=$(printf '%s\n' $SSH_DENIED_BEFORE | grep -vx -e '' -e '{!! $sshUser !!}' | sort -u | xargs || true)
@else
SSH_DENIED=$(printf '%s\n' $SSH_DENIED_BEFORE '{!! $sshUser !!}' | grep -vx -e '' | sort -u | xargs || true)
@endif

if [ "$SSH_DENIED" != "$SSH_DENIED_BEFORE" ]; then
    rm -f "$SSH_CONFIG.swage-old"
    if [ -f "$SSH_CONFIG" ]; then cp -p "$SSH_CONFIG" "$SSH_CONFIG.swage-old"; fi

    if [ -n "$SSH_DENIED" ]; then
        printf '# Managed by Swage: users that may not sign in over SSH.\nDenyUsers %s\n' "$SSH_DENIED" > "$SSH_CONFIG"
        chmod 644 "$SSH_CONFIG"
    else
        rm -f "$SSH_CONFIG"
    fi

    # Ubuntu 24.04 starts sshd on demand, so its runtime directory may not exist yet, and sshd -t refuses to check the
    # config without it.
    install -d -m 755 /run/sshd
    if ! sshd -t; then
        if [ -f "$SSH_CONFIG.swage-old" ]; then mv "$SSH_CONFIG.swage-old" "$SSH_CONFIG"; else rm -f "$SSH_CONFIG"; fi
        echo "sshd rejected the new config, so the previous one was kept." >&2
        exit 1
    fi
    rm -f "$SSH_CONFIG.swage-old"
    systemctl try-reload-or-restart ssh
fi
