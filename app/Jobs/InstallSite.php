<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Sites\SiteEnvironment;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallSite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  string|null  $databaseName  The database created alongside the site, used to pre-fill its environment.
     * @param  DatabaseUser|null  $databaseUser  The database user created alongside the site, whose credentials go in its environment.
     */
    public function __construct(public Site $site, public ?string $databaseName = null, public ?DatabaseUser $databaseUser = null) {}

    /**
     * Execute the job.
     */
    public function handle(RunServerTask $runTask): void
    {
        $this->site->update(['status' => ResourceStatus::Installing]);

        $runTask->run($this->site->server, "Installing site {$this->site->domain}", Script::render('sites.install', [
            'site' => $this->site,
            'certificate' => null,
            'environment' => SiteEnvironment::defaults($this->site, $this->databaseName, $this->databaseUser),
        ]));

        $this->site->update(['status' => ResourceStatus::Installed]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->update(['status' => ResourceStatus::Failed]);
    }
}
