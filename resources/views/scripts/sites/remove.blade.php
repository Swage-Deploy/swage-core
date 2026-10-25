set -Eeuo pipefail

rm -f /etc/nginx/sites-enabled/{!! $site->domain !!} /etc/nginx/sites-available/{!! $site->domain !!} {!! escapeshellarg(App\Sites\SiteNginxConfig::overridePath($site)) !!}
nginx -t
systemctl reload nginx

# Remove any certificate, so certbot stops renewing it for a domain the server no longer serves.
certbot delete --non-interactive --cert-name '{!! $site->domain !!}' > /dev/null 2>&1 || true

@if ($site->isIsolated())
# Remove the site's PHP-FPM pool and its user, with everything in the user's home.
rm -f {!! $site->server->php_versions->map(fn ($php) => escapeshellarg($site->fpmPoolPath($php)))->implode(' ') !!} /etc/sudoers.d/swage-site-{!! $site->system_user !!}
@foreach ($site->server->php_versions as $php)
systemctl reload {!! $php->fpmService() !!}
@endforeach

systemctl disable --now {!! 'swage-redis@'.$site->system_user !!} > /dev/null 2>&1 || true
rm -f /etc/swage-redis/{!! $site->system_user !!}.conf /etc/redis/sites/{!! $site->system_user !!}.conf

if id {!! $site->system_user !!} > /dev/null 2>&1; then
    pkill -KILL -u {!! $site->system_user !!} || true
    userdel --remove {!! $site->system_user !!} 2> /dev/null || rm -rf '{!! $site->homePath() !!}'
fi
@else
rm -rf '{!! $site->path() !!}' '{!! $site->deployKeyPath() !!}' '{!! $site->deployKeyPath() !!}.pub'
@endif
