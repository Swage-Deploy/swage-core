# PHP, Composer, and a PHP-FPM pool running as the application user.

add-apt-repository -y ppa:ondrej/php
apt-get update -q

apt-get install -yq php$PHP_VERSION-fpm php$PHP_VERSION-cli php$PHP_VERSION-common php$PHP_VERSION-mysql \
    php$PHP_VERSION-sqlite3 php$PHP_VERSION-redis php$PHP_VERSION-mbstring php$PHP_VERSION-xml php$PHP_VERSION-curl \
    php$PHP_VERSION-zip php$PHP_VERSION-bcmath php$PHP_VERSION-intl php$PHP_VERSION-gd php$PHP_VERSION-readline

curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

sed -i "s/^user = .*/user = $APP_USER/; s/^group = .*/group = $APP_USER/" /etc/php/$PHP_VERSION/fpm/pool.d/www.conf

for sapi in fpm cli; do
    cat > /etc/php/$PHP_VERSION/$sapi/conf.d/99-swage.ini <<INI
expose_php = Off
allow_url_include = Off
display_errors = Off
log_errors = On
upload_max_filesize = 20M
post_max_size = 20M
INI
done

systemctl restart php$PHP_VERSION-fpm
