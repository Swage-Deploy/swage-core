@include('scripts.partials.write-files', ['files' => (new App\Sites\SiteNginxConfig($site, $certificate))->files($site->nginx_override)])

ln -sf /etc/nginx/sites-available/{!! $site->domain !!} /etc/nginx/sites-enabled/{!! $site->domain !!}

nginx -t
systemctl reload nginx
