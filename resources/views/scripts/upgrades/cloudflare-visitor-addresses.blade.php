set -Eeuo pipefail
@if ($server->isAppServer())

@include('scripts.partials.cloudflare-networks')
@foreach ($sites as $site)

@include('scripts.partials.write-files', ['files' => App\Sites\SiteNginxConfig::for($site)->files($site->nginx_override)])
@endforeach

nginx -t
systemctl reload nginx

# Fetch Cloudflare's current ranges now rather than waiting for the daily refresh. The seeded list works until then.
/usr/local/sbin/swage-cloudflare-ips || echo "Could not refresh Cloudflare's ranges now. The daily refresh tries again."

echo "nginx now sees visitors' addresses behind Cloudflare."
@else

echo "Database servers run no sites, so there is nothing to change."
@endif
