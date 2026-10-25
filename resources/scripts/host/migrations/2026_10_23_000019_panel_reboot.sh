# Lets Swage reboot its own host from the Updates page once automatic updates
# need it. The swage user may only run swage-reboot, which refuses unless
# Ubuntu has flagged that a reboot is required, so a compromised app cannot
# reboot the host whenever it likes.

cat > /usr/local/sbin/swage-reboot <<'SCRIPT'
#!/usr/bin/env bash
#
# Reboots the panel host in a minute, so the request that asked for it can
# still be answered. Only runs when an update has flagged a reboot as required.
#
set -euo pipefail

if [ ! -f /var/run/reboot-required ]; then
    echo "No reboot is required." >&2
    exit 1
fi

shutdown -r +1 "Swage is rebooting the panel host to finish installing updates." > /dev/null 2>&1
echo "Rebooting in one minute."
SCRIPT
chown root:root /usr/local/sbin/swage-reboot
chmod 755 /usr/local/sbin/swage-reboot

cat > /etc/sudoers.d/swage-reboot <<SUDOERS
$APP_USER ALL=(root) NOPASSWD: /usr/local/sbin/swage-reboot
SUDOERS
chmod 440 /etc/sudoers.d/swage-reboot
visudo -cf /etc/sudoers.d/swage-reboot
