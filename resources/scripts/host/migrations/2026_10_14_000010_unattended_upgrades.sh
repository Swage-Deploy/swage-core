# Install security updates automatically. Reboots are left to you.

apt-get install -yq unattended-upgrades

cat > /etc/apt/apt.conf.d/20auto-upgrades <<CONF
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF

cat > /etc/apt/apt.conf.d/52swage-unattended-upgrades <<CONF
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
CONF

systemctl enable --now unattended-upgrades
