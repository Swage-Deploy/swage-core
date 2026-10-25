# nginx serving Swage, with the home directory opened just enough to reach it.

apt-get install -yq nginx

rm -f /etc/nginx/sites-enabled/default

cat > /etc/nginx/conf.d/swage-hardening.conf <<CONF
server_tokens off;
client_max_body_size 20m;
client_body_timeout 15s;
client_header_timeout 15s;
CONF

mkdir -p /var/www/letsencrypt

# nginx (www-data) needs to traverse the home directory to serve public files.
chmod 711 "/home/$APP_USER"

swage_write_vhost
