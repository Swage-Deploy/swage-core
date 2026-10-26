<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsToSelfUpdate;
use App\Enums\DeploymentStatus;
use App\Plugins\Plugin;
use App\Plugins\PluginIndex;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Console\Command;
use RuntimeException;

class SwagePluginUpdate extends Command
{
    use ReportsToSelfUpdate;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:plugin:update
        {names?* : The plugins to update, every installed plugin when omitted}
        {--force : Update without asking for confirmation}
        {--update= : Run an update requested from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update plugins to their newest version and rebuild Swage with them';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdater $updater, PluginInstaller $installer, PluginManifest $manifest, PluginIndex $index): int
    {
        $this->startSelfUpdate();

        if (! $updater->isSupported()) {
            return $this->stop('Plugins can only be updated on installs created by the Swage installer.');
        }

        $names = $this->argument('names');
        $plugins = array_values(array_filter($manifest->all(), fn (Plugin $plugin): bool => $names === [] || in_array($plugin->name, $names, true)));

        if ($plugins === [] || ($names !== [] && count($plugins) !== count($names))) {
            return $this->stop('No installed plugin is named '.implode(', ', array_diff($names, array_map(fn (Plugin $plugin) => $plugin->name, $plugins))).'.');
        }

        $listed = null;
        $updates = [];

        foreach ($plugins as $plugin) {
            // Official plugins are only updated from the repository the index lists for them. Others follow their own.
            if ($plugin->official) {
                try {
                    $listed ??= $index->all();
                } catch (RuntimeException $exception) {
                    return $this->stop($exception->getMessage());
                }

                if (($listed[$plugin->name] ?? null)?->repository !== $plugin->repository) {
                    $this->report("{$plugin->name} is no longer in the plugin index from {$plugin->repository}, so it is not updated.");

                    continue;
                }
            }

            try {
                $available = $installer->availableUpdate($plugin);

                if ($available === null) {
                    $this->report("{$plugin->name} is up to date at {$plugin->ref}.");

                    continue;
                }

                $this->report("==> Fetching {$plugin->name} {$available['ref']}");
                $fetched = $installer->fetch($plugin->repository, $available['ref'], official: $plugin->official);
            } catch (RuntimeException $exception) {
                $installer->pruneCheckouts();

                return $this->stop($exception->getMessage());
            }

            if ($fetched->name !== $plugin->name || $fetched->kind !== $plugin->kind) {
                $installer->pruneCheckouts();

                return $this->stop("{$plugin->repository} now holds {$fetched->name} ({$fetched->kind->value}) instead of {$plugin->name} ({$plugin->kind->value}), so it was not updated.");
            }

            $updates[] = [$plugin, $fetched];
        }

        if ($updates === []) {
            $this->selfUpdate?->log("\nEvery plugin is up to date.\n");
            $this->selfUpdate?->update(['status' => DeploymentStatus::Finished, 'finished_at' => now()]);
            $this->components->info('Every plugin is up to date.');

            return self::SUCCESS;
        }

        foreach ($updates as [$previous, $plugin]) {
            $this->report("{$plugin->name}: {$previous->ref} → {$plugin->ref}");
        }

        if (! $this->option('force') && ! $this->confirm('Update these plugins and rebuild Swage with them?')) {
            $installer->pruneCheckouts();

            return self::FAILURE;
        }

        foreach ($updates as [, $plugin]) {
            $manifest->save($plugin);
        }

        if (! $this->rebuild()) {
            foreach ($updates as [$previous]) {
                $manifest->save($previous);
            }

            $installer->pruneCheckouts();

            return $this->stop('The plugins were not updated, because Swage failed to rebuild with them. The previous release and plugin versions are still running.');
        }

        $installer->pruneCheckouts();

        $this->components->info('Plugins updated.');

        return self::SUCCESS;
    }
}
