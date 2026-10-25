<?php

namespace App\Actions\Sites;

use App\Actions\Servers\RunServerTask;
use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\Sites\SiteNginxConfig;
use App\Ssh\Script;

class ApplySiteNginxOverride
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Write the site's nginx config with the custom nginx, and save it once nginx accepts it.
     *
     * @throws ScriptFailedException
     */
    public function handle(Site $site, ?string $override): void
    {
        $override = filled($override) ? rtrim(str_replace("\r\n", "\n", $override)) : null;

        $this->runTask->run($site->server, "Applying custom nginx for {$site->domain}", Script::render('config.apply', [
            'files' => SiteNginxConfig::for($site)->files($override),
            'validate' => ['nginx -t'],
            'reload' => ['systemctl reload nginx'],
            'healthChecks' => [],
        ]), timeout: 60);

        $site->update(['nginx_override' => $override]);
    }
}
