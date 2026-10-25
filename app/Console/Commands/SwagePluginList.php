<?php

namespace App\Console\Commands;

use App\Plugins\Plugin;
use App\Plugins\PluginManifest;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use Illuminate\Console\Command;

class SwagePluginList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:plugin:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List installed plugins and the source control providers available';

    /**
     * Execute the console command.
     */
    public function handle(PluginManifest $manifest, SourceControlProviders $providers): int
    {
        $plugins = $manifest->all();

        if ($plugins === []) {
            $this->components->info('No plugins are installed.');
        } else {
            $this->table(['Plugin', 'Version', 'Commit', 'Repository'], array_map(fn (Plugin $plugin): array => [
                $plugin->name,
                $plugin->ref,
                $plugin->shortCommit(),
                $plugin->repository,
            ], $plugins));
        }

        $this->components->twoColumnDetail('Source control providers', collect($providers->all())->map(fn (SourceControlProvider $provider): string => $provider->name())->implode(', ') ?: 'none');

        return self::SUCCESS;
    }
}
