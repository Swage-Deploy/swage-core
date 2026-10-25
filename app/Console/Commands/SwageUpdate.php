<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\SelfUpdate;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Console\Command;

class SwageUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:update
        {--force : Rebuild even when already on the latest version}
        {--update= : Run an update queued from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Swage to its newest version with zero downtime';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdater $updater): int
    {
        if ($id = $this->option('update')) {
            $update = SelfUpdate::findOrFail($id);
        } else {
            if (! $updater->isSupported()) {
                $this->components->error('Self-update only works on installs created by the Swage installer.');

                return self::FAILURE;
            }

            $update = SelfUpdate::create([
                'status' => DeploymentStatus::Pending,
                'from_commit' => $updater->currentCommit(),
                'from_version' => $updater->runningVersion(),
            ]);
        }

        $update = $updater->run(
            $update,
            force: (bool) $this->option('force'),
            onOutput: fn (string $output) => $this->output->write($output),
        );

        return $update->status === DeploymentStatus::Finished ? self::SUCCESS : self::FAILURE;
    }
}
