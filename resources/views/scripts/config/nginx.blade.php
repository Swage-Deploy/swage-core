user {!! $server->webUser() !!};
worker_processes {!! $tuning->nginx['worker_processes'] !!};
worker_rlimit_nofile {!! $tuning->nginx['worker_rlimit_nofile'] !!};
pid /run/nginx.pid;
error_log /var/log/nginx/error.log;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections {!! $tuning->nginx['worker_connections'] !!};
    multi_accept on;
    use epoll;
}

http {
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout {!! $tuning->nginx['keepalive_timeout'] !!};
    keepalive_requests 1000;
    types_hash_max_size 2048;
    server_names_hash_bucket_size 128;
    server_tokens off;
    client_max_body_size {!! $tuning->nginx['client_max_body_size'] !!};

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    access_log /var/log/nginx/access.log;

    open_file_cache max={!! $tuning->nginx['open_file_cache_max'] !!} inactive=30s;
    open_file_cache_valid 60s;
    open_file_cache_min_uses 2;
    open_file_cache_errors on;

    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types application/javascript application/json application/xml application/rss+xml image/svg+xml text/css text/javascript text/plain text/xml;

    include {!! App\Provisioning\ServiceConfigFiles::NGINX_OVERRIDE_PATH !!};
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
