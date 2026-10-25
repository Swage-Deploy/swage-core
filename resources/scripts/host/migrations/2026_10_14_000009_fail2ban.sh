# Ban IPs that repeatedly fail SSH authentication.

apt-get install -yq fail2ban

cat > /etc/fail2ban/jail.d/swage.conf <<CONF
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5
backend = systemd

[sshd]
enabled = true

[recidive]
enabled = true
bantime = 1w
findtime = 1d
maxretry = 3
CONF

systemctl enable fail2ban
systemctl restart fail2ban
