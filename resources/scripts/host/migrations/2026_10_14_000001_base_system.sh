# Base packages, timezone and the application user's sudo rules.

apt-get update -q
apt-get upgrade -yq -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
apt-get install -yq software-properties-common curl git unzip acl ufw supervisor cron certbot dnsutils

timedatectl set-timezone UTC

# The app may reload PHP-FPM after activating a release and apply host
# migrations. Nothing else runs as root on its behalf.
cat > /etc/sudoers.d/swage <<SUDOERS
$APP_USER ALL=(root) NOPASSWD: /usr/sbin/service php$PHP_VERSION-fpm reload
$APP_USER ALL=(root) NOPASSWD: /usr/local/sbin/swage-host-migrate
SUDOERS
chmod 440 /etc/sudoers.d/swage
visudo -cf /etc/sudoers.d/swage
