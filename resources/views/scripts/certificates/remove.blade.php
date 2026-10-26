set -Eeuo pipefail

@include('scripts.sites.nginx-update')

@include('scripts.sites.app-url-scheme', ['scheme' => 'http', 'domains' => $site->domains()])

certbot delete --non-interactive --cert-name '{!! $site->directoryName() !!}' || true
