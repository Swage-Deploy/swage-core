@if ($certificate)
server {
    listen 80;
    listen [::]:80;
    server_name {!! implode(' ', $site->domains()) !!};

    location ^~ /.well-known/acme-challenge/ {
        root /home/{!! $user !!}/.letsencrypt;
        default_type text/plain;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

@endif
server {
@if ($certificate)
    {{-- Ubuntu 24.04 ships nginx 1.24, which predates the "http2 on" directive. --}}
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    ssl_certificate /etc/letsencrypt/live/{!! $site->domain !!}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{!! $site->domain !!}/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000" always;
@else
    listen 80;
    listen [::]:80;
@endif
    server_name {!! implode(' ', $site->domains()) !!};
    root {!! $site->webRoot() !!};
    {{-- A link in the site's files is only followed to something its owner also owns, so a site cannot serve another site's files through a link. --}}
    disable_symlinks if_not_owner from=$document_root;
    index index.php index.html;
    charset utf-8;

    {{-- Requests through Cloudflare's proxy take the visitor's address. Requests from anywhere else are left as they are. --}}
    include {!! App\Sites\CloudflareNetworks::CONFIG_PATH !!};
    real_ip_header CF-Connecting-IP;

    access_log /var/log/nginx/{!! $site->domain !!}-access.log;
    error_log /var/log/nginx/{!! $site->domain !!}-error.log error;
@if ($clientMaxBodySize = App\Sites\SitePhpSettings::for($site)->clientMaxBodySize())

    client_max_body_size {!! $clientMaxBodySize !!};
@endif

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    include {!! App\Sites\SiteNginxConfig::overridePath($site) !!};

    location ^~ /.well-known/acme-challenge/ {
        root /home/{!! $user !!}/.letsencrypt;
        default_type text/plain;
    }

@if ($site->octane)
    location / {
        try_files $uri {!! '@octane' !!};
    }
@else
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
@endif

    {{-- Built assets get no add_header of their own, which would drop the server's security headers for them. --}}
    location ^~ /build/ {
        expires 1y;
        access_log off;
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

@if ($site->octane)
    location {!! '@octane' !!} {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_pass http://127.0.0.1:{!! $site->octane_port !!};
    }
@else
    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:{!! $site->fpmSocket() !!};
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
@if ($fastcgiReadTimeout = App\Sites\SitePhpSettings::for($site)->fastcgiReadTimeout())
        fastcgi_read_timeout {!! $fastcgiReadTimeout !!}s;
@endif
    }
@endif

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
