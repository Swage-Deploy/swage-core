<?php

namespace App\Enums;

enum ServerType: string
{
    case App = 'app';
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::App => __('App server'),
            self::Database => __('Database server'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::App => __('nginx, PHP and sites, with an optional local database and Redis.'),
            self::Database => __('MySQL or PostgreSQL only, tuned to use most of the memory, for app servers to connect to.'),
        };
    }
}
