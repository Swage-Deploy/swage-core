set -Eeuo pipefail

# Reboot from a systemd timer a few seconds from now, so this SSH session ends cleanly before the server goes down.
systemd-run --quiet --on-active=5 --timer-property=AccuracySec=1s /bin/systemctl reboot

echo "Rebooting in 5 seconds."
