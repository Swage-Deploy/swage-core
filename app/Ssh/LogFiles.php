<?php

namespace App\Ssh;

use App\Enums\DatabaseEngine;
use App\Models\Daemon;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteCommands;

/**
 * The logs Swage can show for a server or a site.
 */
class LogFiles
{
    /**
     * Get the logs of a site: its application, scheduler, workers, nginx and PHP.
     *
     * @return list<LogFile>
     */
    public static function forSite(Site $site): array
    {
        $logs = [
            LogFile::newest('laravel', 'Laravel', __('Application'), self::applicationLogDirectory($site), 'laravel*.log', readAs: $site->systemUser()),
        ];

        foreach ($site->scheduledJobs()->oldest('id')->get() as $scheduledJob) {
            $logs[] = self::scheduledJob($scheduledJob, $scheduledJob->command === SiteCommands::scheduler($site) ? __('Scheduler') : null);
        }

        foreach ($site->daemons()->oldest('id')->get() as $daemon) {
            $logs[] = self::daemon($daemon, $daemon->isOctane() ? 'Octane' : __('Queue worker #:id', ['id' => $daemon->id]));
        }

        $logs[] = LogFile::file('nginx-access', __('nginx access'), __('Web server'), "/var/log/nginx/{$site->directoryName()}-access.log");
        $logs[] = LogFile::file('nginx-error', __('nginx errors'), __('Web server'), "/var/log/nginx/{$site->directoryName()}-error.log");
        $logs[] = self::phpFpm($site->php_version->value);

        if ($site->private_redis) {
            $logs[] = LogFile::journal('redis', __('Private Redis'), __('Services'), "swage-redis@{$site->system_user}");
        }

        return $logs;
    }

    /**
     * Get the logs of a server's services, processes and system.
     *
     * @return list<LogFile>
     */
    public static function forServer(Server $server): array
    {
        $logs = $server->isAppServer() ? [
            LogFile::file('nginx-error', __('nginx errors'), __('Web server'), '/var/log/nginx/error.log'),
            LogFile::file('nginx-access', __('nginx access'), __('Web server'), '/var/log/nginx/access.log'),
        ] : [];

        foreach ($server->php_versions->sortByDesc('value') as $phpVersion) {
            $logs[] = self::phpFpm($phpVersion->value);
        }

        if ($server->usesMysql()) {
            $logs[] = LogFile::file('mysql', __('MySQL errors'), __('Services'), '/var/log/mysql/error.log');
        }

        if ($server->usesPostgres()) {
            $logs[] = LogFile::file('postgres', 'PostgreSQL', __('Services'), '/var/log/postgresql/postgresql-'.DatabaseEngine::POSTGRES_VERSION.'-main.log');
        }

        if ($server->has_redis) {
            $logs[] = LogFile::file('redis', 'Redis', __('Services'), '/var/log/redis/redis-server.log');
        }

        $logs[] = LogFile::file('supervisor', 'Supervisor', __('Services'), '/var/log/supervisor/supervisord.log');

        foreach ($server->daemons()->with('site')->oldest('id')->get() as $daemon) {
            $logs[] = self::daemon($daemon, $daemon->site ? "{$daemon->site->domain}: #{$daemon->id}" : null);
        }

        foreach ($server->scheduledJobs()->with('site')->oldest('id')->get() as $scheduledJob) {
            $logs[] = self::scheduledJob($scheduledJob, $scheduledJob->site ? "{$scheduledJob->site->domain}: #{$scheduledJob->id}" : null);
        }

        $logs[] = LogFile::file('syslog', __('System'), __('System'), '/var/log/syslog');
        $logs[] = LogFile::file('auth', __('Logins and sudo'), __('System'), '/var/log/auth.log');
        $logs[] = LogFile::file('provision', __('Provisioning'), __('System'), '/root/swage-provision.log');

        return $logs;
    }

    /**
     * Find a log by its key, falling back to the first one.
     *
     * @param  list<LogFile>  $logs
     */
    public static function find(array $logs, ?string $key): LogFile
    {
        foreach ($logs as $log) {
            if ($log->key === $key) {
                return $log;
            }
        }

        return $logs[0];
    }

    /**
     * Zero downtime releases link their storage to the site's shared storage, while sites deployed in place keep
     * theirs in the checkout.
     */
    protected static function applicationLogDirectory(Site $site): string
    {
        return $site->zero_downtime ? $site->path().'/shared/storage/logs' : $site->currentPath().'/storage/logs';
    }

    protected static function phpFpm(string $version): LogFile
    {
        return LogFile::file("php{$version}-fpm", "PHP {$version} FPM", 'PHP', "/var/log/php{$version}-fpm.log");
    }

    protected static function daemon(Daemon $daemon, ?string $label = null): LogFile
    {
        return LogFile::file(
            "daemon-{$daemon->id}",
            $label ?? __('Daemon #:id', ['id' => $daemon->id]),
            __('Processes'),
            $daemon->logPath(),
        );
    }

    protected static function scheduledJob(ScheduledJob $scheduledJob, ?string $label = null): LogFile
    {
        return LogFile::file(
            "scheduled-job-{$scheduledJob->id}",
            $label ?? __('Scheduled job #:id', ['id' => $scheduledJob->id]),
            __('Scheduled jobs'),
            $scheduledJob->logPath(),
            scheduledJobId: $scheduledJob->id,
            readAs: $scheduledJob->user === 'root' ? null : $scheduledJob->user,
        );
    }
}
