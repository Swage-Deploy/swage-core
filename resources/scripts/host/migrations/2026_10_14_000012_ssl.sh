# Let's Encrypt certificate for Swage's domain, renewed by certbot's timer.
# Behind a Cloudflare Tunnel, Cloudflare serves HTTPS and Let's Encrypt cannot
# reach nginx, so no certificate is requested.

if [ "${CLOUDFLARE_TUNNEL:-false}" != true ]; then
    certbot certonly --webroot --non-interactive --agree-tos \
        -m "$EMAIL" \
        -w /var/www/letsencrypt \
        -d "$DOMAIN" \
        --keep-until-expiring \
        --deploy-hook 'systemctl reload nginx'

    # Earlier installers used certbot's nginx plugin, which edits the vhost that
    # migrations now own. Renew those certificates through the webroot instead.
    if ! grep -q '^authenticator = webroot' "/etc/letsencrypt/renewal/$DOMAIN.conf"; then
        certbot reconfigure --non-interactive --cert-name "$DOMAIN" \
            --webroot -w /var/www/letsencrypt \
            --deploy-hook 'systemctl reload nginx'
    fi
fi

swage_write_vhost
