<?php

namespace App\Console\Commands;

use App\Actions\Sites\SetQuickDeploy;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

class SwageDomainChanged extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:domain-changed
        {--from= : The domain Swage was served from before}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Point every quick deploy webhook at the panel\'s new domain, and list what needs updating by hand';

    /**
     * Execute the console command.
     */
    public function handle(SetQuickDeploy $quickDeploy): int
    {
        $sites = Site::query()->with('server.user')->whereNotNull('webhook_id')->orderBy('domain')->get();
        $failed = [];

        foreach ($sites as $site) {
            try {
                $quickDeploy->handle($site, false);
                $quickDeploy->handle($site->refresh(), true);

                $this->components->twoColumnDetail($site->domain, 'Webhook now points at '.route('webhooks.source-control', [$site->source_control, $site]));
            } catch (Throwable $exception) {
                $failed[] = $site->domain;
                $this->components->twoColumnDetail($site->domain, '<fg=red>'.$exception->getMessage().'</>');
            }
        }

        $from = $this->option('from') ? " from {$this->option('from')}" : '';

        $this->newLine();
        $this->line("Swage moved{$from} to ".config('app.url').'. Update these by hand:');
        $this->line('  - The callback URL of each GitHub or GitLab OAuth app the source control plugins use.');
        $this->line('  - Deployment trigger URLs called from CI, shown on each site\'s page.');

        if ($failed !== []) {
            $this->components->error(count($failed).' webhook(s) could not be moved: '.implode(', ', $failed).'. Turn quick deploy off and on again for those sites.');

            return self::FAILURE;
        }

        $this->components->info("Moved {$sites->count()} webhook(s) to the new domain.");

        return self::SUCCESS;
    }
}
