set -Eeuo pipefail

@if ($site->isIsolated())
@include('scripts.sites.php-pool')

@endif

@include('scripts.sites.nginx-update')
