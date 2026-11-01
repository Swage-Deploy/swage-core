<?php

namespace App\Sites;

use App\Enums\PhpVersion;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Provisioning\ServerTuning;
use App\Ssh\Script;

/**
 * The PHP-FPM pool a site runs in.
 *
 * Every site gets its own on-demand pool, so idle sites hold no workers and the memory goes to the busy
 * ones. Each pool may grow as large as the server's secondary pools, which the server's tuning sized from its memory,
 * unless the site's PHP settings give it a limit of its own.
 */
class SitePhpPool
{
    public function __construct(private readonly Site $site, private readonly ServerTuning $tuning) {}

    /**
     * Size the pool from the server's stored tuning, or from its hardware when it has none.
     */
    public static function for(Site $site): self
    {
        $server = $site->server;

        return new self($site, $server->tuning
            ? ServerTuning::fromArray($server->tuning)
            : (new ServerTuner)->tune($server->specs(), $server->usesMysql(), $server->has_redis, $server->usesPostgres(), $server->isAppServer()));
    }

    /**
     * The pool config.
     */
    public function config(): string
    {
        return rtrim(Script::render('config.site-php-fpm-pool', [
            'site' => $this->site,
            'pool' => ['max_children' => $this->maxChildren()] + $this->tuning->php['secondary_pool'],
            'phpValues' => SitePhpSettings::for($this->site)->phpValues(),
        ]))."\n";
    }

    /**
     * The most workers the pool may run at once.
     */
    public function maxChildren(): int
    {
        return SitePhpSettings::for($this->site)->maxChildren() ?? $this->defaultMaxChildren();
    }

    /**
     * The worker limit the server's tuning gives every site's pool.
     */
    public function defaultMaxChildren(): int
    {
        return $this->tuning->php['secondary_pool']['max_children'];
    }

    /**
     * The memory the pool could use with every worker running, in MB.
     */
    public function memoryMb(?int $maxChildren = null): int
    {
        return ($maxChildren ?? $this->maxChildren()) * $this->tuning->php['process_size_mb'];
    }

    /**
     * The memory the server's tuning set aside for PHP, in MB.
     */
    public function memoryBudgetMb(): int
    {
        return $this->tuning->php['memory_budget_mb'];
    }

    /**
     * The pool files to write, keyed by path.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        return [$this->site->fpmPoolPath($this->site->php_version) => $this->config()];
    }

    /**
     * Pool files the site may have left under the server's other PHP versions.
     *
     * @return list<string>
     */
    public function stalePaths(): array
    {
        return $this->site->server->php_versions
            ->reject(fn (PhpVersion $php): bool => $php === $this->site->php_version)
            ->map(fn (PhpVersion $php): string => $this->site->fpmPoolPath($php))
            ->values()
            ->all();
    }
}
