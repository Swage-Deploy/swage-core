set -euo pipefail

# Swage's own SSH connection says which address the new panel host connects from, as this server sees it.
new=${SSH_CLIENT%% *}
[[ "$new" =~ ^[0-9A-Fa-f:.]+$ ]] || { echo 'Unable to tell which address this panel host connects from.'; exit 1; }
old={!! escapeshellarg(implode(' ', $old)) !!}
changed=no

# fail2ban never bans the addresses in ignoreip, which held the old panel host's. Swap in the new one.
for file in /etc/fail2ban/jail.d/*.conf; do
    [ -f "$file" ] && grep -q '^ignoreip = ' "$file" || continue

    awk -v new="$new" -v old="$old" '
        BEGIN { n = split(old, list, " "); for (i = 1; i <= n; i++) drop[list[i]] = 1 }
        /^ignoreip = / {
            line = "ignoreip ="; seen = 0
            for (i = 3; i <= NF; i++) {
                if ($i in drop && $i != new) continue
                if ($i == new) seen = 1
                line = line " " $i
            }
            if (!seen) line = line " " new
            print line
            next
        }
        { print }
    ' "$file" > "$file.swage-moved"

    if cmp -s "$file" "$file.swage-moved"; then
        rm -f "$file.swage-moved"
    else
        cat "$file.swage-moved" > "$file"
        rm -f "$file.swage-moved"
        changed=yes
    fi
done

if command -v fail2ban-client > /dev/null && systemctl is-active --quiet fail2ban; then
    fail2ban-client unban "$new" > /dev/null 2>&1 || true

    if [ "$changed" = yes ]; then
        fail2ban-client reload > /dev/null
        echo "Reachable from $new. fail2ban now leaves that address alone."
        exit 0
    fi
fi

echo "Reachable from $new."
