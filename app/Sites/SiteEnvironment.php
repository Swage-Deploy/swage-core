<?php

namespace App\Sites;

use App\Models\DatabaseUser;
use App\Models\Site;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SiteEnvironment
{
    /**
     * Build a production-ready .env for a new Laravel site.
     *
     * The site connects to MySQL or PostgreSQL as its own database user, on the engine of its database or else the server's
     * first. Without a user the credentials are left blank, never filled with Swage's server-wide account, which can reach
     * every database on the server.
     */
    public static function defaults(Site $site, ?string $databaseName = null, ?DatabaseUser $databaseUser = null): string
    {
        $server = $site->server;
        $engine = $databaseUser->engine ?? $server->defaultDatabaseEngine();
        $driver = $server->has_redis ? 'redis' : 'database';

        $sections = [
            [
                'APP_NAME' => '"'.Str::of($site->domain)->before('.')->headline().'"',
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_DEBUG' => 'false',
                'APP_URL' => "http://{$site->domain}",
            ],
            [
                'LOG_CHANNEL' => 'daily',
                'LOG_LEVEL' => 'error',
            ],
        ];

        if ($engine !== null) {
            $sections[] = [
                'DB_CONNECTION' => $engine->connection(),
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => (string) $engine->port(),
                'DB_DATABASE' => $databaseName ?? 'laravel',
                'DB_USERNAME' => $databaseUser->username ?? '',
                'DB_PASSWORD' => $databaseUser->password ?? '',
            ];
        }

        $sections[] = [
            'CACHE_STORE' => $driver,
            'QUEUE_CONNECTION' => $driver,
            'SESSION_DRIVER' => $driver,
        ];

        if ($server->has_redis) {
            $sections[] = self::redis($site);
        }

        return collect($sections)
            ->map(fn (array $section): string => collect($section)
                ->map(fn (string $value, string $key): string => "{$key}={$value}")
                ->implode("\n"))
            ->implode("\n\n");
    }

    /**
     * The Redis connection settings for a site: its private server's socket, or the shared server with the
     * password Swage gives shared sites once the server requires one.
     *
     * @return array<string, string>
     */
    public static function redis(Site $site): array
    {
        if ($site->private_redis) {
            return [
                'REDIS_HOST' => $site->redisSocket(),
                'REDIS_PORT' => '0',
            ];
        }

        return [
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6379',
            ...($site->server->usesRedisAuthentication() ? [
                'REDIS_USERNAME' => config('swage.user'),
                'REDIS_PASSWORD' => $site->server->redis_password,
            ] : []),
        ];
    }

    /**
     * Validate the name of a .env key.
     *
     * @throws InvalidArgumentException
     */
    public static function key(string $key): string
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) {
            throw new InvalidArgumentException("[{$key}] is not a valid environment key.");
        }

        return $key;
    }

    /**
     * Format a .env line, quoting the value unless it only contains characters that are safe unquoted.
     *
     * @throws InvalidArgumentException
     */
    public static function line(string $key, string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException("The value of [{$key}] may not contain line breaks.");
        }

        if (preg_match('/^[A-Za-z0-9._\/:@+=,-]*$/D', $value)) {
            return self::key($key).'='.$value;
        }

        return self::key($key).'="'.addcslashes($value, '"\\$').'"';
    }
}
