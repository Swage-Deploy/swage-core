set -Eeuo pipefail

certbot certonly --webroot --non-interactive --agree-tos \
    --email {!! escapeshellarg($email) !!} \
    --webroot-path /home/{!! $user !!}/.letsencrypt \
    --cert-name '{!! $site->directoryName() !!}' \
    --deploy-hook 'systemctl reload nginx' \
@foreach ($certificate->domains as $domain)
    -d '{!! $domain !!}' \
@endforeach
    --keep-until-expiring --expand

openssl x509 -enddate -noout -in /etc/letsencrypt/live/{!! $site->directoryName() !!}/cert.pem | sed 's/^notAfter=/SWAGE_EXPIRES /'

@include('scripts.sites.nginx-update')

@include('scripts.sites.app-url-scheme', ['scheme' => 'https', 'domains' => $certificate->domains])
