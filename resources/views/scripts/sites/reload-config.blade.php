# Deployments cache the config and opcache never re-reads files, so rebuild the cache and reload PHP.
if [ -f '{!! $site->currentPath() !!}/bootstrap/cache/config.php' ]; then
    sudo -u {!! $site->systemUser() !!} -H {!! $site->php_version->binary() !!} '{!! $site->currentPath() !!}/artisan' config:cache \
        || echo "Warning: the config cache could not be rebuilt. Deploy the site to pick up the change."
fi
@if ($site->octane)
sudo -u {!! $site->systemUser() !!} -H {!! $site->php_version->binary() !!} '{!! $site->currentPath() !!}/artisan' octane:reload || true
@else
systemctl reload {!! $site->php_version->fpmService() !!}
@endif
