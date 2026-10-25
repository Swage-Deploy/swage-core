if [ -f /var/run/reboot-required ]; then
    echo SWAGE_REBOOT_REQUIRED
    sort -u /var/run/reboot-required.pkgs 2> /dev/null || true
fi
