set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

@include('scripts.partials.php-repository')

# Security updates for PHP follow it to the new repository.
if [ -f /etc/apt/apt.conf.d/52swage-unattended-upgrades ]; then
    sed -i 's/"origin=LP-PPA-ondrej-php,/"origin=deb.sury.org,/' /etc/apt/apt.conf.d/52swage-unattended-upgrades
fi

echo "PHP now installs and updates from packages.sury.org."
