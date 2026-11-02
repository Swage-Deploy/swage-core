<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\SelfUpdate;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SwageAutoUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:auto-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Swage to its newest version, then its plugins, as the daily automatic update does';

    /**
     * Execute the console command.
     *
     * Swage goes first, so plugins are checked and built by the release they will run with. When Swage's update fails,
     * the plugins wait for the next day: updating them rebuilds Swage at the same version, which would fail too.
     */
    public function handle(SelfUpdater $updater, PluginManifest $manifest): int
    {
        if (! $updater->isSupported()) {
            $this->components->error('Self-update only works on installs created by the Swage installer.');

            return self::FAILURE;
        }

        if ($updater->isUpdating()) {
            $this->components->warn('An update is already running, so the automatic update waits for tomorrow.');

            return self::SUCCESS;
        }

        $latest = $updater->latest();

        if ($latest === null) {
            $this->components->error('Unable to reach the repository.');

            return self::FAILURE;
        }

        if ($updater->isNewer($latest, $updater->currentCommit(), $updater->currentVersion())) {
            $update = $updater->run(SelfUpdate::create([
                'automatic' => true,
                'status' => DeploymentStatus::Pending,
                'from_commit' => $updater->currentCommit(),
                'from_version' => $updater->runningVersion(),
            ]), onOutput: fn (string $output) => $this->output->write($output));

            if ($update->status !== DeploymentStatus::Finished) {
                $this->components->error('Swage failed to update, so its plugins were not updated.');

                return self::FAILURE;
            }
        } else {
            $this->components->info('Swage is up to date.');
        }

        if ($manifest->all() === []) {
            return self::SUCCESS;
        }

        // The plugins are updated by the live release, which may be the one just installed.
        $result = Process::path($updater->root().'/current')
            ->env($updater->environment())
            ->forever()
            ->run([config('swage.self_update.php_binary'), 'artisan', 'swage:plugin:update', '--force', '--automatic'], fn (string $type, string $output) => $this->output->write($output));

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
