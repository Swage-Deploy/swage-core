# Network and kernel hardening.

cat > /etc/sysctl.d/90-swage-hardening.conf <<CONF
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv4.conf.all.log_martians = 1
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.tcp_syncookies = 1
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
kernel.unprivileged_bpf_disabled = 1
net.core.bpf_jit_harden = 2
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
fs.suid_dumpable = 0
CONF

# A container shares its host's kernel, so only the settings namespaced to it
# can change; the rest stay as the container host set them.
if systemd-detect-virt --quiet --container; then
    sysctl -q -p /etc/sysctl.d/90-swage-hardening.conf 2> /dev/null \
        || echo "Running in a container: kernel settings it cannot change were left to the container host."
else
    sysctl --system > /dev/null
fi
