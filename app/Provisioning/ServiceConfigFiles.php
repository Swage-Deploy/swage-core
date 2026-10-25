<?php

namespace App\Provisioning;

use App\Enums\ConfigService;
use App\Enums\DatabaseEngine;
use App\Enums\PhpVersion;
use App\Models\Server;
use App\Ssh\Script;

/**
 * The config files Swage writes for each service on a server, with the user's overrides taking precedence.
 */
final class ServiceConfigFiles
{
    public const string NGINX_OVERRIDE_PATH = '/etc/nginx/swage/http.conf';

    public const string REDIS_OVERRIDE_PATH = '/etc/redis/swage-custom.conf';

    public const string POSTGRES_CONFIG_DIRECTORY = '/etc/postgresql/'.DatabaseEngine::POSTGRES_VERSION.'/main/conf.d';

    /**
     * @var array<string, string|null>
     */
    private array $overrides;

    /**
     * @param  array<string, string|null>|null  $overrides  The server's saved overrides when null.
     */
    public function __construct(
        private readonly Server $server,
        private readonly ServerTuning $tuning,
        ?array $overrides = null,
    ) {
        $this->overrides = $overrides ?? ($server->config_overrides ?? []);
    }

    /**
     * Build the files from the server's stored tuning and overrides.
     */
    public static function for(Server $server): self
    {
        return new self($server, ServerTuning::fromArray($server->tuning));
    }

    /**
     * Get a copy that uses a different override for one service, such as an unsaved draft.
     */
    public function withOverride(ConfigService $service, ?string $override): self
    {
        return new self($this->server, $this->tuning, [...$this->overrides, $service->value => $override]);
    }

    /**
     * Swage's own config for a service, before overrides are applied. PHP configs are shown for the default version.
     */
    public function base(ConfigService $service): string
    {
        $php = $this->server->default_php_version;

        return match ($service) {
            ConfigService::Nginx => $this->render('config.nginx'),
            ConfigService::PhpFpmIni => $this->render('config.php-ini', ['fpm' => true]),
            ConfigService::PhpCliIni => $this->render('config.php-ini', ['fpm' => false]),
            ConfigService::PhpFpmPool => $this->render('config.php-fpm-pool', ['php' => $php, 'pool' => $this->pool($php)]),
            ConfigService::Mysql => $this->render('config.mysql'),
            ConfigService::Postgres => $this->render('config.postgres'),
            ConfigService::Redis => $this->render('config.redis'),
        };
    }

    /**
     * The path of Swage's main file for a service. PHP paths are given for the default version.
     */
    public function basePath(ConfigService $service): string
    {
        $php = $this->server->default_php_version?->value;

        return match ($service) {
            ConfigService::Nginx => '/etc/nginx/nginx.conf',
            ConfigService::PhpFpmIni => "/etc/php/{$php}/fpm/conf.d/99-swage.ini",
            ConfigService::PhpCliIni => "/etc/php/{$php}/cli/conf.d/99-swage.ini",
            ConfigService::PhpFpmPool => "/etc/php/{$php}/fpm/pool.d/www.conf",
            ConfigService::Mysql => '/etc/mysql/mysql.conf.d/99-swage.cnf',
            ConfigService::Postgres => self::POSTGRES_CONFIG_DIRECTORY.'/99-swage.conf',
            ConfigService::Redis => '/etc/redis/swage.conf',
        };
    }

    /**
     * The files to write for a service, keyed by path.
     *
     * @return array<string, string>
     */
    public function files(ConfigService $service): array
    {
        $override = $this->override($service);

        return match ($service) {
            ConfigService::Nginx => [
                '/etc/nginx/nginx.conf' => $this->merged($service, $this->render('config.nginx')),
                self::NGINX_OVERRIDE_PATH => $this->withNewline($override),
            ],
            ConfigService::PhpFpmIni, ConfigService::PhpCliIni => $this->server->php_versions->flatMap(function (PhpVersion $php) use ($service, $override): array {
                $directory = "/etc/php/{$php->value}/".($service === ConfigService::PhpFpmIni ? 'fpm' : 'cli').'/conf.d';

                return [
                    "{$directory}/99-swage.ini" => $this->merged($service, $this->render('config.php-ini', ['fpm' => $service === ConfigService::PhpFpmIni])),
                    "{$directory}/99-swage-custom.ini" => $this->withNewline($override),
                ];
            })->all(),
            ConfigService::PhpFpmPool => $this->server->php_versions->mapWithKeys(fn (PhpVersion $php): array => [
                "/etc/php/{$php->value}/fpm/pool.d/www.conf" => $this->merged($service, $this->render('config.php-fpm-pool', ['php' => $php, 'pool' => $this->pool($php)]))
                    .($override === null ? '' : "\n; Custom settings saved in Swage\n".$this->withNewline($override)),
            ])->all(),
            ConfigService::Mysql => [
                '/etc/mysql/mysql.conf.d/99-swage.cnf' => $this->merged($service, $this->render('config.mysql')),
                '/etc/mysql/mysql.conf.d/99-swage-custom.cnf' => $override === null || str_starts_with(ltrim($override), '[')
                    ? $this->withNewline($override)
                    : "[mysqld]\n".$this->withNewline($override),
            ],
            // PostgreSQL reads conf.d in name order and the last value wins, so the custom file comes second.
            ConfigService::Postgres => [
                self::POSTGRES_CONFIG_DIRECTORY.'/99-swage.conf' => $this->merged($service, $this->render('config.postgres')),
                self::POSTGRES_CONFIG_DIRECTORY.'/99-swage-custom.conf' => $this->withNewline($override),
            ],
            ConfigService::Redis => [
                '/etc/redis/swage.conf' => $this->merged($service, $this->render('config.redis')),
                self::REDIS_OVERRIDE_PATH => $this->withNewline($override),
            ],
        };
    }

    /**
     * Commands that must succeed after the files are written and before the service picks them up.
     *
     * @return list<string>
     */
    public function validationCommands(ConfigService $service): array
    {
        return match ($service) {
            ConfigService::Nginx => ['nginx -t'],
            ConfigService::PhpFpmIni, ConfigService::PhpFpmPool => $this->server->php_versions
                ->map(fn (PhpVersion $php): string => "check_clean php-fpm{$php->value} -t")->values()->all(),
            ConfigService::PhpCliIni => $this->server->php_versions
                ->map(fn (PhpVersion $php): string => "check_clean {$php->binary()} -r 'echo \"ok\";'")->values()->all(),
            ConfigService::Mysql => ['mysqld --validate-config'],
            // Catches unknown settings without a restart. A bad value only fails the restart, which the health check undoes.
            ConfigService::Postgres => ['check_clean sudo -u postgres /usr/lib/postgresql/'.DatabaseEngine::POSTGRES_VERSION.'/bin/postgres -C shared_buffers --config-file=/etc/postgresql/'.DatabaseEngine::POSTGRES_VERSION.'/main/postgresql.conf'],
            ConfigService::Redis => [],
        };
    }

    /**
     * Commands that make the service pick up the new files.
     *
     * @return list<string>
     */
    public function reloadCommands(ConfigService $service): array
    {
        return match ($service) {
            ConfigService::Nginx => ['systemctl reload nginx'],
            ConfigService::PhpFpmIni, ConfigService::PhpFpmPool => $this->server->php_versions
                ->map(fn (PhpVersion $php): string => "systemctl reload {$php->fpmService()}")->values()->all(),
            ConfigService::PhpCliIni => [],
            ConfigService::Mysql => ['systemctl restart mysql'],
            ConfigService::Postgres => ['systemctl restart postgresql'],
            ConfigService::Redis => ['systemctl restart redis-server'],
        };
    }

    /**
     * Commands that confirm the service is healthy after picking up the new files.
     *
     * @return list<string>
     */
    public function healthChecks(ConfigService $service): array
    {
        return match ($service) {
            ConfigService::Mysql => ['mysqladmin ping --silent'],
            ConfigService::Postgres => ['pg_isready --quiet'],
            // Once Redis requires a password an anonymous ping is refused, which still proves it is up.
            ConfigService::Redis => ["redis-cli ping 2>&1 | grep -qE 'PONG|NOAUTH'"],
            ConfigService::PhpFpmIni, ConfigService::PhpFpmPool => $this->server->php_versions
                ->map(fn (PhpVersion $php): string => "systemctl is-active --quiet {$php->fpmService()}")->values()->all(),
            default => [],
        };
    }

    public function override(ConfigService $service): ?string
    {
        $override = $this->overrides[$service->value] ?? null;

        return filled($override) ? str_replace("\r\n", "\n", rtrim($override)) : null;
    }

    private function merged(ConfigService $service, string $base): string
    {
        return $this->withNewline(ConfigMerger::withoutOverridden(
            $base,
            $this->override($service),
            $service->syntax(),
            $service === ConfigService::Nginx ? 'http' : null,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data = []): string
    {
        return rtrim(Script::render($template, ['server' => $this->server, 'tuning' => $this->tuning, ...$data]));
    }

    /**
     * @return array<string, int|string>
     */
    private function pool(PhpVersion $php): array
    {
        return $php === $this->server->default_php_version ? $this->tuning->php['primary_pool'] : $this->tuning->php['secondary_pool'];
    }

    private function withNewline(?string $contents): string
    {
        return $contents === null || $contents === '' ? '' : rtrim($contents)."\n";
    }
}
