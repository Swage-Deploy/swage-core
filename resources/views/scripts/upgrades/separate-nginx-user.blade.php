set -Eeuo pipefail

# www-data joins the groups of the users whose sites nginx serves, and Swage's user leaves the isolated sites' groups.
usermod -aG {!! $user !!} www-data
@foreach ($isolatedSites as $site)
if id {!! $site->system_user !!} > /dev/null 2>&1; then
    usermod -aG {!! $site->system_user !!} www-data
    gpasswd -d {!! $user !!} {!! $site->system_user !!} > /dev/null 2>&1 || true
fi
@endforeach

# nginx first: as www-data it can still reach the current sockets, which belong to the {!! $user !!} group.
@include('scripts.partials.write-files', ['files' => $nginxFiles])
chown -R www-data:www-data /var/lib/nginx
nginx -t
systemctl reload nginx

# Then PHP-FPM, whose sockets now belong to www-data. A restart is needed for the new socket owner to apply.
@include('scripts.partials.write-files', ['files' => $poolFiles])
@foreach ($isolatedSites as $site)
@include('scripts.partials.write-files', ['files' => App\Sites\SitePhpPool::for($site)->files()])
@endforeach
@foreach ($server->php_versions as $php)
php-fpm{!! $php->value !!} -t
@endforeach
@foreach ($server->php_versions as $php)
systemctl restart {!! $php->fpmService() !!}
@endforeach
@if ($sharedDaemons->isNotEmpty())

# Running workers keep the groups they started with, so restart the ones that run as {!! $user !!}.
@foreach ($sharedDaemons as $daemon)
supervisorctl restart '{!! $daemon->programName() !!}:*' || echo "Warning: {!! $daemon->programName() !!} could not be restarted."
@endforeach
@endif

echo "nginx now runs as www-data."
