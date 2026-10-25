# Tunnel installs reloaded nginx onto its loopback addresses, which fails while
# it still listens on every address from when it was installed, so it kept
# serving Ubuntu's default site. A restart puts Swage's config in place.

if [ "${CLOUDFLARE_TUNNEL:-false}" != true ]; then
    return 0
fi

if ss -Hltn 'sport = :80' | grep -qE '(0\.0\.0\.0|\[::\]|\*):80'; then
    systemctl restart nginx
    echo "Restarted nginx so it only listens for cloudflared."
fi
