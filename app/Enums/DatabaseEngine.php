<?php

namespace App\Enums;

enum DatabaseEngine: string
{
    case Mysql = 'mysql';
    case Postgres = 'postgres';

    /**
     * The PostgreSQL major version Ubuntu 24.04 ships.
     */
    public const string POSTGRES_VERSION = '16';

    /**
     * Get the name of the database server.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL',
            self::Postgres => 'PostgreSQL',
        };
    }

    /**
     * Get the name of the database server with the version Swage installs.
     */
    public function labelWithVersion(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL 8',
            self::Postgres => 'PostgreSQL '.self::POSTGRES_VERSION,
        };
    }

    /**
     * Get the Laravel connection a site uses for the database server.
     */
    public function connection(): string
    {
        return match ($this) {
            self::Mysql => 'mysql',
            self::Postgres => 'pgsql',
        };
    }

    public function port(): int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Postgres => 5432,
        };
    }
}
