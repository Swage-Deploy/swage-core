<?php

namespace App\Actions\Sites;

use App\Actions\Servers\RunServerTask;
use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\Ssh\Script;

class UpdateSiteEnvironment
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Set or remove keys in the site's .env, then reload its config, PHP and queue workers so they pick them up.
     *
     * @param  array<string, string|null>  $values  Null removes the key.
     * @param  string  $setup  Script that runs first, such as starting a service the new values point at.
     *
     * @throws ScriptFailedException
     */
    public function handle(Site $site, array $values, string $taskName, string $setup = ''): void
    {
        $site->load('daemons');

        $this->runTask->run($site->server, $taskName, Script::render('sites.update-environment', [
            'site' => $site,
            'values' => $values,
            'setup' => rtrim($setup),
        ]), timeout: 180);
    }
}
