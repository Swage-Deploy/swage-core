set -Eeuo pipefail

@include('scripts.sites.php-pool')

@include('scripts.sites.nginx-update')
@if ($previousDomain ?? null)

@include('scripts.sites.app-url-domain', ['from' => $previousDomain, 'to' => $site->domain])
@endif
