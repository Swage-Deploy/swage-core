# Key-only SSH. Refuses to run without a root key, so it can never lock you out.

if ! grep -qE '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys 2> /dev/null; then
    echo "No SSH key in /root/.ssh/authorized_keys. Add one before disabling password logins." >&2
    exit 1
fi

# Files in sshd_config.d are read in order and the first value wins, so this
# overrides cloud-init's 50-cloud-init.conf.
cat > /etc/ssh/sshd_config.d/00-swage-hardening.conf <<CONF
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitEmptyPasswords no
PubkeyAuthentication yes
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
AllowAgentForwarding no
ClientAliveInterval 300
ClientAliveCountMax 2
CONF

# Ubuntu 24.04 starts sshd on demand, so its runtime directory may not exist
# yet, and sshd -t refuses to check the config without it.
install -d -m 755 /run/sshd
sshd -t
systemctl try-reload-or-restart ssh
