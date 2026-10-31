<?php

namespace App\Jobs;

use App\Actions\Sites\ReadSiteEnvironment;
use App\Actions\Sites\UpdateSiteEnvironment;
use App\Enums\ResourceStatus;
use App\Models\Site;
use Dotenv\Dotenv;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConfigureReverbEnvironment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /**
     * Point the app's broadcasting and front end at the site's own address, which nginx passes on to the Reverb server,
     * using the scheme of its APP_URL.
     */
    public function handle(ReadSiteEnvironment $readEnvironment, UpdateSiteEnvironment $updateEnvironment): void
    {
        $appUrl = Dotenv::parse($readEnvironment->handle($this->site))['APP_URL'] ?? '';
        $secure = parse_url($appUrl, PHP_URL_SCHEME) !== 'http';

        $updateEnvironment->handle($this->site, [
            'REVERB_HOST' => $this->site->domain,
            'REVERB_PORT' => $secure ? '443' : '80',
            'REVERB_SCHEME' => $secure ? 'https' : 'http',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => (string) $this->site->reverb_port,
        ], "Pointing Reverb settings at {$this->site->domain}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->update(['status' => ResourceStatus::Failed]);
    }
}
