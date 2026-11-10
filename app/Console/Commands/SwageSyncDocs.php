<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SwageSyncDocs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:sync-docs {source=../swagedeployapp : The swagedeployapp checkout to copy the docs from}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy the Swage documentation into resources/docs, where the MCP server reads it, before a release';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = rtrim($this->argument('source'), '/');
        $config = "{$source}/config/docs.php";

        if (! File::exists($config)) {
            $this->components->error("No docs at {$source}. Pass the path to a swagedeployapp checkout.");

            return self::FAILURE;
        }

        $sections = (require $config)['sections'] ?? [];
        $target = resource_path('docs');

        File::ensureDirectoryExists($target);
        File::delete(File::glob("{$target}/*.md"));

        $index = [];

        foreach ($sections as $section => $pages) {
            $listed = [];

            foreach ($pages as $slug => $title) {
                if (! File::exists($page = "{$source}/resources/docs/{$slug}.md")) {
                    $this->components->warn("Skipping {$slug}, which has no page.");

                    continue;
                }

                File::copy($page, "{$target}/{$slug}.md");
                $listed[] = ['slug' => $slug, 'title' => $title];
            }

            $index[] = ['title' => $section, 'pages' => $listed];
        }

        File::put("{$target}/index.json", json_encode(['sections' => $index], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->components->info('Copied '.collect($index)->sum(fn (array $section): int => count($section['pages'])).' pages into resources/docs.');

        return self::SUCCESS;
    }
}
