<?php

namespace App\Enums;

use App\Models\Server;

enum ConfigService: string
{
    case Nginx = 'nginx';
    case PhpFpmIni = 'php-fpm-ini';
    case PhpCliIni = 'php-cli-ini';
    case PhpFpmPool = 'php-fpm-pool';
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Redis = 'redis';

    /**
     * Get the human readable name of the service config.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nginx => 'nginx',
            self::PhpFpmIni => 'PHP-FPM php.ini',
            self::PhpCliIni => 'PHP CLI php.ini',
            self::PhpFpmPool => 'PHP-FPM pool',
            self::Mysql => 'MySQL',
            self::Postgres => 'PostgreSQL',
            self::Redis => 'Redis',
        };
    }

    /**
     * Explain where the custom config is placed.
     */
    public function description(): string
    {
        return match ($this) {
            self::Nginx => __('Directives and blocks for the http block of nginx.conf. For a single site, use the site\'s nginx page.'),
            self::PhpFpmIni => __('php.ini settings for every PHP version\'s FPM workers.'),
            self::PhpCliIni => __('php.ini settings for every PHP version\'s command line, used by deployments, queues and the scheduler.'),
            self::PhpFpmPool => __('Settings for the www pool of every PHP version, such as pm.* or php_admin_value[...].'),
            self::Mysql => __('Options for the [mysqld] section. Saving restarts MySQL.'),
            self::Postgres => __('postgresql.conf settings. Saving restarts PostgreSQL.'),
            self::Redis => __('Redis directives. Saving restarts Redis.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Nginx => 'globe-alt',
            self::PhpFpmIni, self::PhpCliIni, self::PhpFpmPool => 'code-bracket',
            self::Mysql, self::Postgres => 'circle-stack',
            self::Redis => 'bolt',
        };
    }

    /**
     * Get the syntax used to match the custom config against Swage's.
     */
    public function syntax(): ConfigSyntax
    {
        return match ($this) {
            self::Nginx => ConfigSyntax::Nginx,
            self::PhpFpmIni, self::PhpCliIni, self::PhpFpmPool, self::Postgres => ConfigSyntax::Ini,
            self::Mysql => ConfigSyntax::Mysql,
            self::Redis => ConfigSyntax::Redis,
        };
    }

    /**
     * Determine if the server runs the service.
     */
    public function isAvailableOn(Server $server): bool
    {
        return match ($this) {
            self::Mysql => $server->usesMysql(),
            self::Postgres => $server->usesPostgres(),
            self::Redis => (bool) $server->has_redis,
            self::Nginx, self::PhpFpmIni, self::PhpCliIni, self::PhpFpmPool => $server->isAppServer(),
        };
    }

    /**
     * Get the services a server runs.
     *
     * @return list<self>
     */
    public static function availableOn(Server $server): array
    {
        return array_values(array_filter(self::cases(), fn (self $service): bool => $service->isAvailableOn($server)));
    }
}
