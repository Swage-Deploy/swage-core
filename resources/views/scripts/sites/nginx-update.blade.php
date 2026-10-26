@include('scripts.partials.write-files', ['files' => (new App\Sites\SiteNginxConfig($site, $certificate))->files($site->nginx_override)])

ln -sf /etc/nginx/sites-available/{!! $site->directoryName() !!} /etc/nginx/sites-enabled/{!! $site->directoryName() !!}

nginx -t
systemctl reload nginx
