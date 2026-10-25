<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Certificate;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RemoveCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Certificate $certificate) {}

    /**
     * Switch the site back to plain HTTP and delete the certificate.
     */
    public function handle(RunServerTask $runTask): void
    {
        $site = $this->certificate->site;

        $runTask->run($site->server, "Removing certificate for {$site->domain}", Script::render('certificates.remove', [
            'site' => $site,
            'certificate' => null,
        ]));

        $this->certificate->delete();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->certificate->update(['status' => ResourceStatus::Failed]);
    }
}
