# The ondrej PPA on Launchpad is being retired in favour of packages.sury.org,
# which carries the same PHP packages. Install and update PHP from there.

php_repository

if [ -f /etc/apt/apt.conf.d/52swage-unattended-upgrades ]; then
    sed -i 's/"origin=LP-PPA-ondrej-php,/"origin=deb.sury.org,/' /etc/apt/apt.conf.d/52swage-unattended-upgrades
    systemctl restart unattended-upgrades
fi
