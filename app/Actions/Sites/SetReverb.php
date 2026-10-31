<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\ConfigureReverbEnvironment;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Site;
use App\Sites\SiteCommands;
use App\Sites\SitePorts;
use App\Ssh\RemoteShell;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SetReverb
{
    /**
     * The first local port handed out to Reverb servers, Reverb's own default.
     */
    public const int FIRST_PORT = 8080;

    /**
     * Run the site's Reverb server and send its WebSocket traffic to it, or stop it.
     */
    public function handle(Site $site, bool $enabled): void
    {
        if ($enabled === $site->reverb) {
            return;
        }

        $enabled ? $this->enable($site) : $this->disable($site);
    }

    /**
     * Determine whether the site's live release has laravel/reverb installed, or null when the server cannot say.
     *
     * The answer is remembered until the site is next deployed, so opening the page does not reach the server every
     * time. Only a definite answer is remembered, never a server that could not be reached.
     */
    public static function installed(Site $site, RemoteShell $shell): ?bool
    {
        $installed = Cache::get(self::packageCacheKey($site));

        if ($installed !== null) {
            return $installed;
        }

        try {
            $result = $shell->run($site->server, 'test -f '.escapeshellarg($site->currentPath().'/vendor/laravel/reverb/composer.json').' && echo installed || echo missing', timeout: 20);
        } catch (Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        $installed = trim($result->output) === 'installed';

        Cache::put(self::packageCacheKey($site), $installed, now()->addDay());

        return $installed;
    }

    /**
     * The cache key for whether laravel/reverb is in the site's live release, which only a deployment can change.
     */
    public static function packageCacheKey(Site $site): string
    {
        return "reverb-installed:{$site->id}";
    }

    /**
     * Start the Reverb server before nginx sends it traffic, then point the app's broadcasting and front end at it.
     */
    protected function enable(Site $site): void
    {
        if ($site->reverb_port === null) {
            SitePorts::assign($site, self::FIRST_PORT, fn (int $port) => $site->update(['reverb_port' => $port]));
        }

        $site->update(['reverb' => true, 'status' => ResourceStatus::Installing]);

        $daemon = $site->server->daemons()->create([
            'site_id' => $site->id,
            'command' => SiteCommands::reverb($site),
            'directory' => $site->currentPath(),
            'user' => $site->systemUser(),
            'processes' => 1,
            'stop_wait_seconds' => 15,
            'status' => ResourceStatus::Pending,
        ]);

        Bus::chain([new InstallDaemon($daemon), new UpdateSiteConfiguration($site), new ConfigureReverbEnvironment($site)])
            ->catch(fn () => $site->update(['status' => ResourceStatus::Failed]))
            ->dispatch();
    }

    /**
     * Stop sending WebSocket traffic to the Reverb server before stopping it. The site keeps its port, so turning Reverb
     * back on needs no change to what the app was built with.
     */
    protected function disable(Site $site): void
    {
        $site->update(['reverb' => false, 'status' => ResourceStatus::Installing]);

        $jobs = [new UpdateSiteConfiguration($site)];

        if ($daemon = $site->reverbDaemon()) {
            $daemon->update(['status' => ResourceStatus::Removing]);
            $jobs[] = new RemoveDaemon($daemon);
        }

        Bus::chain($jobs)
            ->catch(fn () => $site->update(['status' => ResourceStatus::Failed]))
            ->dispatch();
    }
}
