set -Eeuo pipefail

# The key is written as the site's user, so a symlink planted in its place cannot point root at another file.
sudo -u {!! $site->systemUser() !!} sh -c 'umask 077 && cat > "$1"' sh '{!! $site->deployKeyPath() !!}' <<'SWAGE_EOF'
{!! trim($site->deploy_key_private) !!}
SWAGE_EOF
sudo -u {!! $site->systemUser() !!} chmod 600 '{!! $site->deployKeyPath() !!}'

echo '{!! $site->deploy_key_public !!}' | sudo -u {!! $site->systemUser() !!} tee '{!! $site->deployKeyPath() !!}.pub' > /dev/null
