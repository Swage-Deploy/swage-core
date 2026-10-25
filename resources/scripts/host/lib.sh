#!/usr/bin/env bash
#
# Helpers shared by host migrations. Every migration runs with these loaded and
# with /etc/swage/swage.conf exported: REPOSITORY, BRANCH, DOMAIN, EMAIL,
# APP_USER, APP_ROOT, PHP_VERSION, NODE_VERSION and, on installs since
# Cloudflare Tunnel support, CLOUDFLARE_TUNNEL.
#
# Migrations run once each, but must still be safe to re-run after a failure
# part way through, so prefer writing whole files over appending to them.
#

# Run a command as the application user.
as_app() {
    sudo -u "$APP_USER" -H "$@"
}

# Read a value from /etc/swage/secrets.env, generating and storing it first if missing.
swage_secret() {
    local key=$1 file=/etc/swage/secrets.env

    touch "$file"
    chmod 600 "$file"

    if ! grep -q "^$key=" "$file"; then
        echo "$key=$(openssl rand -hex 24)" >> "$file"
    fi

    grep "^$key=" "$file" | cut -d= -f2-
}

# Write the nginx site for Swage, serving HTTPS once a certificate exists, or
# only to cloudflared when Swage is behind a Cloudflare Tunnel.
swage_write_vhost() {
    local certificate="/etc/letsencrypt/live/$DOMAIN"
    local socket="/run/php/php$PHP_VERSION-fpm.sock"
    local tunnel_https=''

    {
        if [ "${CLOUDFLARE_TUNNEL:-false}" = true ]; then
            # Cloudflare serves HTTPS, so PHP is told requests are secure for cookies and generated URLs.
            tunnel_https='fastcgi_param HTTPS on;'

            cat <<NGINX
server {
    # Only cloudflared, on this server, can connect. It passes on the visitor's
    # address, which rate limiting and logs need instead of the loopback one.
    listen 127.0.0.1:80;
    listen [::1]:80;
    set_real_ip_from 127.0.0.1;
    set_real_ip_from ::1;
    real_ip_header CF-Connecting-IP;
NGINX
        elif [ -f "$certificate/fullchain.pem" ]; then
            cat <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    # Ubuntu 24.04 ships nginx 1.24, which predates the "http2 on" directive.
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    ssl_certificate $certificate/fullchain.pem;
    ssl_certificate_key $certificate/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
NGINX
        else
            cat <<NGINX
server {
    listen 80;
    listen [::]:80;
NGINX
        fi

        cat <<NGINX
    server_name $DOMAIN;
    root $APP_ROOT/current/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:$socket;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        $tunnel_https
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
    } > /etc/nginx/sites-available/swage

    ln -sf /etc/nginx/sites-available/swage /etc/nginx/sites-enabled/swage
    nginx -t

    # A reload cannot move nginx from every address, where it listens straight
    # after it is installed, to the loopback addresses for the same port: the
    # bind fails and nginx quietly keeps its old config. Only a restart can.
    if [ "${CLOUDFLARE_TUNNEL:-false}" = true ]; then
        systemctl restart nginx
    else
        systemctl reload nginx
    fi
}
