# PHP comes from Ondřej Surý's repository at packages.sury.org, which replaces his PPA on Launchpad. The PPA's source is
# removed so apt doesn't fetch the same packages from both.
rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources

if [ ! -f /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
    keyring=$(mktemp)
    curl -fsSL --proto '=https' https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$keyring"
    dpkg -i "$keyring"
    rm -f "$keyring"
fi

. /etc/os-release
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list
apt-get update -q
