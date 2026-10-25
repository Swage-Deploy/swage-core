<?php

namespace App\Jobs;

use App\Models\Server;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads whether a server needs a reboot, which Ubuntu flags when an update, such as a new kernel, only takes effect
 * after one. Automatic security updates never reboot servers, so Swage shows it on the server's page instead.
 *
 * The check is not recorded in the server's activity log, since it runs every hour without changing anything.
 */
class CheckRebootRequired implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    /**
     * Execute the job. A server that cannot be reached keeps what was last read, to be checked again next hour.
     */
    public function handle(RemoteShell $shell): void
    {
        try {
            $result = $shell->run($this->server, Script::render('reboot-required'), timeout: 30);
        } catch (Throwable) {
            return;
        }

        if ($result->failed()) {
            return;
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $result->output))));

        if (($lines[0] ?? null) !== 'SWAGE_REBOOT_REQUIRED') {
            $this->server->update(['reboot_required_at' => null, 'reboot_required_packages' => null]);

            return;
        }

        $this->server->update([
            'reboot_required_at' => $this->server->reboot_required_at ?? now(),
            'reboot_required_packages' => array_slice($lines, 1),
        ]);
    }
}
