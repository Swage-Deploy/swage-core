set -Eeuo pipefail
@if ($server->isAppServer() && $sites->isNotEmpty())
@foreach ($sites as $site)

@include('scripts.partials.write-files', ['files' => App\Sites\SiteNginxConfig::for($site)->files($site->nginx_override)])
@endforeach

nginx -t
systemctl reload nginx

echo "nginx now only follows links in a site's files to files their owner owns."
@else

echo "No sites run here, so there is nothing to change."
@endif
