set -Eeuo pipefail

@if ($site->isIsolated())
@include('scripts.sites.php-pool')

@endif

@include('scripts.sites.nginx-update')
@if ($previousDomain ?? null)

@include('scripts.sites.app-url-domain', ['from' => $previousDomain, 'to' => $site->domain])
@endif
