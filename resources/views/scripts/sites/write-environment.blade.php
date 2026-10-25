set -Eeuo pipefail

# The site's user writes its own .env, so a symlink planted in its place cannot point root at another file.
sudo -u {!! $site->systemUser() !!} sh -c 'umask 077 && cat > "$1"' sh '{!! $site->path() !!}/shared/.env' <<'{!! $delimiter !!}'
{!! $environment !!}
{!! $delimiter !!}

@if ($clearConfig)
if [ -f '{!! $site->currentPath() !!}/artisan' ]; then
    sudo -u {!! $site->systemUser() !!} -H {!! $site->php_version->binary() !!} '{!! $site->currentPath() !!}/artisan' config:clear \
        || echo "Warning: the config cache could not be cleared."
fi
@endif
@if ($site->octane)
sudo -u {!! $site->systemUser() !!} -H {!! $site->php_version->binary() !!} '{!! $site->currentPath() !!}/artisan' octane:reload || true
@else
service {!! $site->php_version->fpmService() !!} reload
@endif
