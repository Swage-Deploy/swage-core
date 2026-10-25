# Deny all inbound traffic except SSH (rate limited) and HTTP/HTTPS.

ufw default deny incoming
ufw default allow outgoing

# Earlier installers allowed SSH outright. That rule would match before the
# rate limit, so remove it first.
ufw delete allow OpenSSH > /dev/null 2>&1 || true
ufw limit OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
