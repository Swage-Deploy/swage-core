<?php

use App\Enums\ConfigSyntax;
use App\Provisioning\ConfigMerger;

test('ini overrides remove the matching swage settings', function () {
    $base = "memory_limit = 256M\nupload_max_filesize = 100M\n; a comment\nopcache.enable = 1";

    $merged = ConfigMerger::withoutOverridden($base, "MEMORY_LIMIT=1G\n; ignored = 1\n[PHP]", ConfigSyntax::Ini);

    expect($merged)->toBe("upload_max_filesize = 100M\n; a comment\nopcache.enable = 1");
});

test('mysql overrides match options written with dashes or underscores', function () {
    $base = "[mysqld]\ninnodb_buffer_pool_size = 1024M\nskip-name-resolve\nmax_connections = 100";

    $merged = ConfigMerger::withoutOverridden($base, "innodb-buffer-pool-size = 2G\nskip_name_resolve = OFF", ConfigSyntax::Mysql);

    expect($merged)->toBe("[mysqld]\nmax_connections = 100");
});

test('redis overrides match repeatable directives on their first argument', function () {
    $base = "maxmemory 512mb\nmaxmemory-policy volatile-lru\nsave 900 1\nsave 300 10";

    $merged = ConfigMerger::withoutOverridden($base, "maxmemory 1gb\nsave 900 5", ConfigSyntax::Redis);

    expect($merged)->toBe("maxmemory-policy volatile-lru\nsave 300 10");
});

test('nginx overrides replace directives and blocks inside the target block only', function () {
    $base = <<<'NGINX'
    worker_processes 4;
    events {
        worker_connections 1024;
    }
    http {
        client_max_body_size 100m;
        gzip on;
        add_header X-Frame-Options "SAMEORIGIN";
        add_header X-Content-Type-Options "nosniff";
        location / {
            try_files $uri /index.php;
        }
        location = /favicon.ico { access_log off; }
    }
    NGINX;

    $override = <<<'NGINX'
    # Bigger uploads
    client_max_body_size 1g;
    worker_connections 9999;
    add_header X-Frame-Options "DENY";
    location / {
        return 200 "ok;{";
    }
    NGINX;

    expect(ConfigMerger::withoutOverridden($base, $override, ConfigSyntax::Nginx, 'http'))->toBe(<<<'NGINX'
    worker_processes 4;
    events {
        worker_connections 1024;
    }
    http {
        gzip on;
        add_header X-Content-Type-Options "nosniff";
        location = /favicon.ico { access_log off; }
    }
    NGINX);
});

test('site nginx overrides apply to the last server block', function () {
    $base = "server {\n    listen 80;\n    return 301 https://\$host;\n}\n\nserver {\n    listen 443 ssl http2;\n    client_max_body_size 100m;\n}";

    $merged = ConfigMerger::withoutOverridden($base, 'client_max_body_size 5m; listen 80;', ConfigSyntax::Nginx, 'server');

    expect($merged)->toBe("server {\n    listen 80;\n    return 301 https://\$host;\n}\n\nserver {\n    listen 443 ssl http2;\n}");
});

test('single line nginx blocks are replaced as a whole', function () {
    $base = "server {\n    location = /robots.txt { access_log off; log_not_found off; }\n    index index.php;\n}";

    expect(ConfigMerger::withoutOverridden($base, 'location = /robots.txt { return 404; }', ConfigSyntax::Nginx, 'server'))
        ->toBe("server {\n    index index.php;\n}");
});

test('annotations mark the lines an override replaces', function () {
    $annotated = ConfigMerger::annotate("a = 1\nb = 2", 'b = 3', ConfigSyntax::Ini);

    expect($annotated)->toBe([
        ['line' => 'a = 1', 'overridden' => false],
        ['line' => 'b = 2', 'overridden' => true],
    ]);
});

test('an empty override leaves swage config untouched', function () {
    $base = "http {\n    gzip on;\n}";

    expect(ConfigMerger::withoutOverridden($base, null, ConfigSyntax::Nginx, 'http'))->toBe($base)
        ->and(ConfigMerger::withoutOverridden('a = 1', '', ConfigSyntax::Ini))->toBe('a = 1');
});
