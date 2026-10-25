# Ubuntu's unattended upgrades only install updates from Ubuntu's own archive,
# so PHP from the ondrej PPA, Node.js from NodeSource and cloudflared never got
# security updates on their own. Reboots are still left to you.

cat > /etc/apt/apt.conf.d/52swage-unattended-upgrades <<CONF
Unattended-Upgrade::Origins-Pattern {
    "origin=LP-PPA-ondrej-php,codename=\${distro_codename}";
    "site=deb.nodesource.com";
    "origin=cloudflared";
};
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
CONF

systemctl restart unattended-upgrades
