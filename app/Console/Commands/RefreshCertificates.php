<?php

namespace App\Console\Commands;

use App\Enums\ResourceStatus;
use App\Enums\ServerStatus;
use App\Jobs\RefreshCertificateExpiry;
use App\Models\Certificate;
use Illuminate\Console\Command;

class RefreshCertificates extends Command
{
    /**
     * Certbot renews certificates once they have 30 days left, so only those are checked for a new expiry date.
     */
    public const int RENEWAL_WINDOW_DAYS = 30;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:refresh-certificates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read the new expiry dates of certificates that certbot may have renewed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $certificates = Certificate::query()
            ->where('status', ResourceStatus::Installed)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '<', now()->addDays(self::RENEWAL_WINDOW_DAYS)))
            ->whereHas('site.server', fn ($query) => $query->where('status', ServerStatus::Active))
            ->with('site.server')
            ->get();

        foreach ($certificates->groupBy('site.server_id') as $serverCertificates) {
            RefreshCertificateExpiry::dispatch($serverCertificates->first()->site->server, $serverCertificates->values());
        }

        $this->components->info("Checking {$certificates->count()} certificate(s).");

        return self::SUCCESS;
    }
}
