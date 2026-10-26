<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Server;
use App\Ssh\RemoteShell;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the expiry dates of a server's certificates, which change whenever certbot renews them on the server.
 *
 * The read is not recorded in the server's activity log, since it runs every day without changing anything.
 */
class RefreshCertificateExpiry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  Collection<int, Certificate>  $certificates  Certificates of sites on the server.
     */
    public function __construct(public Server $server, public Collection $certificates) {}

    /**
     * Execute the job. A server that cannot be reached keeps the dates it had, to be read again tomorrow.
     */
    public function handle(RemoteShell $shell): void
    {
        $certificates = $this->certificates->loadMissing('site')->keyBy(fn (Certificate $certificate): string => $certificate->site->directoryName());

        $script = $certificates->keys()->map(fn (string $name): string => sprintf(
            'f=%s; if [ -f "$f" ]; then echo "SWAGE_CERT %s $(openssl x509 -enddate -noout -in "$f" | cut -d= -f2)"; fi',
            escapeshellarg("/etc/letsencrypt/live/{$name}/cert.pem"),
            $name,
        ))->implode("\n");

        try {
            $result = $shell->run($this->server, $script, timeout: 30);
        } catch (Throwable) {
            return;
        }

        preg_match_all('/^SWAGE_CERT (\S+) (.+)$/m', $result->output, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $expiresAt]) {
            $certificates->get($name)?->update(['expires_at' => Carbon::parse(trim($expiresAt))]);
        }
    }
}
