# The recidive jail read the journal, where fail2ban's own bans never appear, so
# addresses banned for an hour were never banned for a week. Point it at the log
# file fail2ban writes its bans to.

if [ -f /etc/fail2ban/jail.d/swage.conf ] && ! grep -q '^backend = auto$' /etc/fail2ban/jail.d/swage.conf; then
    sed -i 's/^\[recidive\]$/# recidive reads the bans fail2ban writes to its own log file, which isn'"'"'t in the journal.\n[recidive]\nbackend = auto/' /etc/fail2ban/jail.d/swage.conf
    systemctl restart fail2ban
fi
