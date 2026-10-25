# Databases are restored from dumps uploaded to the panel, which can be far
# larger than the 20 MB it accepted before. Livewire refuses files over 512 MB,
# so requests are allowed a little more for the form data around them.

for sapi in fpm cli; do
    cat > /etc/php/$PHP_VERSION/$sapi/conf.d/99-swage.ini <<INI
expose_php = Off
allow_url_include = Off
display_errors = Off
log_errors = On
upload_max_filesize = 520M
post_max_size = 520M
INI
done

cat > /etc/nginx/conf.d/swage-hardening.conf <<CONF
server_tokens off;
client_max_body_size 520m;
client_body_timeout 15s;
client_header_timeout 15s;
CONF

nginx -t
systemctl reload php$PHP_VERSION-fpm
systemctl reload nginx
