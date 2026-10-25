{{-- sudo skips files with a dot in their name, so the rule is checked under a temporary name before it can take effect. --}}
cat > /etc/sudoers.d/{!! $sudoersFile !!}.new <<'SWAGE_EOF'
{!! $sudoUser !!} ALL=(root) NOPASSWD: /usr/local/sbin/swage-reload-php, /usr/local/sbin/swage-restart-daemon
SWAGE_EOF
chmod 440 /etc/sudoers.d/{!! $sudoersFile !!}.new
visudo -cf /etc/sudoers.d/{!! $sudoersFile !!}.new
mv /etc/sudoers.d/{!! $sudoersFile !!}.new /etc/sudoers.d/{!! $sudoersFile !!}
