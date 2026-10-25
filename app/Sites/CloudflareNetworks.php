<?php

namespace App\Sites;

/**
 * The addresses Cloudflare's proxy connects from, published at https://www.cloudflare.com/ips/. App servers refresh
 * them every day, so these only seed a new server until its first refresh.
 */
final class CloudflareNetworks
{
    /**
     * The nginx config that lists the ranges, which every site includes.
     */
    public const string CONFIG_PATH = '/etc/nginx/swage/cloudflare.conf';

    /**
     * @var list<string>
     */
    public const array RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * The config for the ranges, in the same form the server's daily refresh writes it.
     */
    public static function config(): string
    {
        return collect(self::RANGES)->map(fn (string $range): string => "set_real_ip_from {$range};")->implode("\n")."\n";
    }
}
