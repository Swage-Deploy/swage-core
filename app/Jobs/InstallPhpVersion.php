<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\PhpVersion;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class InstallPhpVersion implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Server $server, public PhpVersion $php) {}

    /**
     * Install another PHP version beside the server's others, then re-tune the server, which writes the new version's
     * PHP-FPM pool and settings, including any custom PHP settings saved in Swage.
     */
    public function handle(RunServerTask $runTask): void
    {
        $runTask->run($this->server, "Installing PHP {$this->php->value}", Script::render('php.install-version', [
            'php' => $this->php,
        ]), timeout: 1200);

        $this->server->update([
            'php_versions' => $this->server->php_versions
                ->push($this->php)
                ->unique()
                ->sortBy(fn (PhpVersion $php): string => $php->value, SORT_NATURAL)
                ->values(),
        ]);

        Bus::dispatchSync(new OptimizeServer($this->server));

        $this->server->update(['installing_php' => null]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->server->update(['installing_php' => null]);
    }
}
