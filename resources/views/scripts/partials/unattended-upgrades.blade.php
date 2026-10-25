# Install security updates every day, including PHP from the ondrej PPA and Node.js from NodeSource on app servers, which
# Ubuntu's defaults leave out. Servers are never rebooted automatically: Swage shows when one needs it.
apt-get install -yq unattended-upgrades

cat > /etc/apt/apt.conf.d/20auto-upgrades <<'SWAGE_EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
SWAGE_EOF

cat > /etc/apt/apt.conf.d/52swage-unattended-upgrades <<'SWAGE_EOF'
@if ($server->isAppServer())
Unattended-Upgrade::Origins-Pattern {
    "origin=LP-PPA-ondrej-php,codename=${distro_codename}";
    "site=deb.nodesource.com";
};
@endif
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
SWAGE_EOF

systemctl enable --now unattended-upgrades
