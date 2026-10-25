<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsToSelfUpdate;
use App\Plugins\PluginIndex;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Console\Command;
use RuntimeException;

class SwagePluginInstall extends Command
{
    use ReportsToSelfUpdate;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:plugin:install
        {name : The plugin\'s name in the plugin index, such as swage/gitlab}
        {--ref= : A tag or branch to install instead of the newest version}
        {--force : Install without asking for confirmation}
        {--update= : Run an install requested from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a plugin from the plugin index and rebuild Swage with it';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdater $updater, PluginInstaller $installer, PluginManifest $manifest, PluginIndex $index): int
    {
        $this->startSelfUpdate();

        if (! $updater->isSupported()) {
            return $this->stop('Plugins can only be installed on installs created by the Swage installer. During development, require the plugin with Composer instead.');
        }

        $this->components->warn('A plugin runs inside Swage with access to everything it stores, including the SSH keys of every server. Only install plugins you trust.');
        try {
            $listed = $index->find($this->argument('name'));
        } catch (RuntimeException $exception) {
            return $this->stop($exception->getMessage());
        }

        if ($listed === null) {
            return $this->stop("{$this->argument('name')} is not in the plugin index. Only plugins it lists can be installed.");
        }

        $this->report("==> Fetching {$listed->name} from {$listed->repository}".($this->option('ref') ? " at {$this->option('ref')}" : ''));

        try {
            $plugin = $installer->fetch($listed->repository, $this->option('ref') ?: null);
        } catch (RuntimeException $exception) {
            return $this->stop($exception->getMessage());
        }

        // The repository must hold the package the index lists, so a listing cannot install something else.
        if ($plugin->name !== $listed->name || $plugin->kind !== $listed->kind) {
            $installer->pruneCheckouts();

            return $this->stop("{$listed->repository} holds {$plugin->name} ({$plugin->kind->value}), not {$listed->name} ({$listed->kind->value}) as the plugin index lists.");
        }

        $previous = $manifest->find($plugin->name);

        $this->selfUpdate?->update(['plugin_operation' => [...$this->selfUpdate->plugin_operation, 'name' => $plugin->name, 'ref' => $plugin->ref]]);
        $this->report("Plugin: {$plugin->name}");
        $this->report("Version: {$plugin->ref} at {$plugin->commit}");

        if ($previous) {
            $this->report("Replaces: {$previous->ref} at {$previous->commit}");
        }

        if (! $this->option('force') && ! $this->confirm('Install this plugin and rebuild Swage with it?')) {
            $installer->pruneCheckouts();

            return self::FAILURE;
        }

        $manifest->save($plugin);

        if (! $this->rebuild()) {
            $previous ? $manifest->save($previous) : $manifest->remove($plugin->name);
            $installer->pruneCheckouts();

            return $this->stop("{$plugin->name} was not installed, because Swage failed to rebuild with it. The previous release is still running.");
        }

        $installer->pruneCheckouts();

        $this->components->info("{$plugin->name} is installed.");

        return self::SUCCESS;
    }
}
