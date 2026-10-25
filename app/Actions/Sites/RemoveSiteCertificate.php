<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\RemoveCertificate;
use App\Models\Site;

class RemoveSiteCertificate
{
    /**
     * Remove the site's certificate and serve it over HTTP again. A certificate that failed to install is just forgotten.
     */
    public function handle(Site $site): void
    {
        $certificate = $site->certificate;

        if (! $certificate) {
            return;
        }

        if ($certificate->status === ResourceStatus::Failed) {
            $certificate->delete();
        } else {
            $certificate->update(['status' => ResourceStatus::Removing]);
            RemoveCertificate::dispatch($certificate);
        }

        $site->unsetRelation('certificate');
    }
}
