<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Certificate;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class ObtainCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public Certificate $certificate) {}

    /**
     * Request a Let's Encrypt certificate and switch the site to HTTPS.
     */
    public function handle(RunServerTask $runTask): void
    {
        $site = $this->certificate->site;

        $this->certificate->update(['status' => ResourceStatus::Installing]);

        $result = $runTask->run($site->server, "Obtaining certificate for {$site->domain}", Script::render('certificates.obtain', [
            'site' => $site,
            'certificate' => $this->certificate,
            'email' => config('swage.certificate_email') ?: $site->server->user->email,
        ]));

        preg_match('/^SWAGE_EXPIRES (.+)$/m', $result->output, $matches);

        $this->certificate->update([
            'status' => ResourceStatus::Installed,
            'expires_at' => isset($matches[1]) ? Carbon::parse(trim($matches[1])) : null,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->certificate->update(['status' => ResourceStatus::Failed]);
    }
}
