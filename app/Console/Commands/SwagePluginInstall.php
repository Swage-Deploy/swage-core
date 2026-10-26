<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsToSelfUpdate;
use App\Plugins\Plugin;
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
        {name? : The plugin\'s name in the plugin index, such as swage/gitlab}
        {--url= : Install an unofficial plugin straight from this git repository instead}
        {--ref= : A tag or branch to install instead of the newest version}
        {--commit= : The commit the plugin must be at, as it was when the install was checked}
        {--force : Install without asking for confirmation}
        {--update= : Run an install requested from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a plugin from the plugin index, or from a git repository, and rebuild Swage with it';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdater $updater, PluginInstaller $installer, PluginManifest $manifest, PluginIndex $index): int
    {
        $this->startSelfUpdate();

        if (! $updater->isSupported()) {
            return $this->stop('Plugins can only be installed on installs created by the Swage installer. During development, require the plugin with Composer instead.');
        }

        $url = $this->option('url');

        if (blank($this->argument('name')) === blank($url)) {
            return $this->stop('Give the name of a plugin in the plugin index, or the git repository of another plugin with --url.');
        }

        $this->components->warn('A plugin runs inside Swage with access to everything it stores, including the SSH keys of every server. Only install plugins you trust.');

        try {
            $plugin = filled($url)
                ? $this->fetchUnofficial($installer, $index, $url)
                : $this->fetchOfficial($installer, $index, $this->argument('name'));
        } catch (RuntimeException $exception) {
            $installer->pruneCheckouts();

            return $this->stop($exception->getMessage());
        }

        if (filled($this->option('commit')) && $plugin->commit !== $this->option('commit')) {
            $installer->pruneCheckouts();

            return $this->stop("{$plugin->repository} is now at {$plugin->commit}, not {$this->option('commit')} as it was when the install was checked. Check it again before installing.");
        }

        $previous = $manifest->find($plugin->name);

        // An official plugin is not replaced with an unofficial copy, nor one unofficial plugin with another of its name.
        // Official plugins follow whichever repository the index lists.
        if ($previous && ($previous->official !== $plugin->official || (! $plugin->official && $previous->repository !== $plugin->repository))) {
            $installer->pruneCheckouts();

            return $this->stop("{$plugin->name} is already installed from {$previous->repository}. Remove it first to install it from {$plugin->repository}.");
        }

        $this->selfUpdate?->update(['plugin_operation' => [...$this->selfUpdate->plugin_operation, 'name' => $plugin->name, 'ref' => $plugin->ref]]);
        $this->report("Plugin: {$plugin->name}");
        $this->report("Version: {$plugin->ref}");
        $this->report('Source: '.($plugin->official ? 'the plugin index' : "{$plugin->repository}, not reviewed or signed by Swage"));

        if ($previous) {
            $this->report("Replaces: {$previous->ref}");
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

    /**
     * Fetch a plugin the index lists, from the repository it lists.
     *
     * @throws RuntimeException
     */
    protected function fetchOfficial(PluginInstaller $installer, PluginIndex $index, string $name): Plugin
    {
        $listed = $index->find($name) ?? throw new RuntimeException("{$name} is not in the plugin index. Install other plugins from their git repository with --url.");

        $this->report("==> Fetching {$listed->name} from {$listed->repository}".($this->option('ref') ? " at {$this->option('ref')}" : ''));

        $plugin = $installer->fetch($listed->repository, $this->option('ref') ?: null, official: true);

        // The repository must hold the package the index lists, so a listing cannot install something else.
        if ($plugin->name !== $listed->name || $plugin->kind !== $listed->kind) {
            throw new RuntimeException("{$listed->repository} holds {$plugin->name} ({$plugin->kind->value}), not {$listed->name} ({$listed->kind->value}) as the plugin index lists.");
        }

        return $plugin;
    }

    /**
     * Fetch a plugin straight from a git repository. It may not use the name of a plugin the index lists, so it is never
     * mistaken for the official one.
     *
     * @throws RuntimeException
     */
    protected function fetchUnofficial(PluginInstaller $installer, PluginIndex $index, string $url): Plugin
    {
        if (! preg_match(PluginInstaller::REPOSITORY_PATTERN, $url)) {
            throw new RuntimeException('Enter the plugin\'s git URL, such as https://github.com/acme/swage-gitea or git@github.com:acme/swage-gitea.git.');
        }

        $this->report("==> Fetching the plugin from {$url}".($this->option('ref') ? " at {$this->option('ref')}" : ''));
        $this->report('This plugin is not in the plugin index, so Swage has not reviewed it and does not check its signature.');

        $plugin = $installer->fetch($url, $this->option('ref') ?: null);

        try {
            $listed = $index->find($plugin->name);
        } catch (RuntimeException) {
            $listed = null;
            $this->report('The plugin index could not be reached to check the plugin\'s name against it.');
        }

        if ($listed !== null) {
            throw new RuntimeException("{$plugin->name} is an official plugin. Install it from the plugin index instead, so it comes from {$listed->repository}.");
        }

        return $plugin;
    }
}
