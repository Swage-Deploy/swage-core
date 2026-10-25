<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsToSelfUpdate;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginKind;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use Illuminate\Console\Command;

class SwagePluginRemove extends Command
{
    use ReportsToSelfUpdate;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:plugin:remove
        {name : The plugin\'s package name, as swage:plugin:list shows it}
        {--force : Remove without asking for confirmation}
        {--update= : Run a removal requested from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a plugin and rebuild Swage without it';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdater $updater, PluginInstaller $installer, PluginManifest $manifest): int
    {
        $this->startSelfUpdate();

        $plugin = $manifest->find($this->argument('name'));

        if ($plugin === null) {
            return $this->stop("No plugin named {$this->argument('name')} is installed.");
        }

        if (! $updater->isSupported()) {
            return $this->stop('Plugins can only be removed on installs created by the Swage installer.');
        }

        if ($plugin->kind === PluginKind::SourceControl) {
            $this->components->warn('Sites using a provider this plugin adds keep deploying from their clone URL, but their deploy keys and webhooks can no longer be changed.');
        }

        if (! $this->option('force') && ! $this->confirm("Remove {$plugin->name} and rebuild Swage without it?")) {
            return self::FAILURE;
        }

        $this->report("==> Removing {$plugin->name}");
        $manifest->remove($plugin->name);

        if (! $this->rebuild()) {
            $manifest->save($plugin);

            return $this->stop("{$plugin->name} was not removed, because Swage failed to rebuild without it. The previous release is still running.");
        }

        $installer->pruneCheckouts();

        $this->components->info("{$plugin->name} is removed.");

        return self::SUCCESS;
    }
}
