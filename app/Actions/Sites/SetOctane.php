<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Sites\SiteCommands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

class SetOctane
{
    /**
     * The first local port handed out to Octane servers.
     */
    public const int FIRST_PORT = 8000;

    public function __construct(protected ServerTuner $tuner) {}

    /**
     * Serve the site with Octane on FrankenPHP, or switch it back to PHP-FPM.
     *
     * @throws ValidationException
     */
    public function handle(Site $site, bool $enabled): void
    {
        if ($enabled && ! $site->supportsOctane()) {
            throw ValidationException::withMessages([
                'octane' => __('Octane is not available for sites that use zero downtime deployments.'),
            ]);
        }

        if ($enabled === $site->octane) {
            return;
        }

        $enabled ? $this->enable($site) : $this->disable($site);
    }

    /**
     * Start the Octane server before nginx starts sending it traffic.
     */
    protected function enable(Site $site): void
    {
        $site->update([
            'octane' => true,
            'octane_port' => $site->octane_port ?? $this->nextAvailablePort($site),
            'status' => ResourceStatus::Installing,
        ]);

        $daemon = $site->server->daemons()->create([
            'site_id' => $site->id,
            'command' => SiteCommands::octane($site, $this->workers($site)),
            'directory' => $site->currentPath(),
            'user' => $site->systemUser(),
            'processes' => 1,
            'stop_wait_seconds' => 30,
            'status' => ResourceStatus::Pending,
        ]);

        Bus::chain([new InstallDaemon($daemon), new UpdateSiteConfiguration($site)])
            ->catch(fn () => $site->update(['status' => ResourceStatus::Failed]))
            ->dispatch();
    }

    /**
     * Point nginx back at PHP-FPM before stopping the Octane server.
     */
    protected function disable(Site $site): void
    {
        $site->update(['octane' => false, 'status' => ResourceStatus::Installing]);

        $jobs = [new UpdateSiteConfiguration($site)];

        if ($daemon = $site->octaneDaemon()) {
            $daemon->update(['status' => ResourceStatus::Removing]);
            $jobs[] = new RemoveDaemon($daemon);
        }

        Bus::chain($jobs)
            ->catch(fn () => $site->update(['status' => ResourceStatus::Failed]))
            ->dispatch();
    }

    /**
     * Size the worker pool from the server's hardware.
     */
    protected function workers(Site $site): int
    {
        $specs = $site->server->specs();

        if (! $specs) {
            return 1;
        }

        return $this->tuner->tune($specs, $site->server->usesMysql(), $site->server->has_redis, $site->server->usesPostgres(), $site->server->isAppServer())->php['octane_workers'];
    }

    protected function nextAvailablePort(Site $site): int
    {
        $highest = $site->server->sites()->whereKeyNot($site->id)->max('octane_port');

        return $highest ? $highest + 1 : self::FIRST_PORT;
    }
}
