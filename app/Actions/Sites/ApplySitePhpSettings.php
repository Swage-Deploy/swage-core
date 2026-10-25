<?php

namespace App\Actions\Sites;

use App\Actions\Servers\RunServerTask;
use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\Sites\SiteNginxConfig;
use App\Sites\SitePhpPool;
use App\Ssh\Script;

class ApplySitePhpSettings
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Write the site's PHP-FPM pool and nginx config with the settings, and save them once both accept them. The
     * previous config is put back if either is rejected.
     *
     * @param  array{memory_limit?: string, upload_max_filesize?: string, max_execution_time?: int, max_input_vars?: int, directives?: array<string, string>}|null  $settings
     *
     * @throws ScriptFailedException
     */
    public function handle(Site $site, ?array $settings): void
    {
        $draft = clone $site;
        $draft->php_settings = $settings;

        $this->runTask->run($site->server, "Applying PHP settings for {$site->domain}", Script::render('config.apply', [
            'files' => SitePhpPool::for($draft)->files() + SiteNginxConfig::for($draft)->files($site->nginx_override),
            'validate' => ["php-fpm{$site->php_version->value} -t", 'nginx -t'],
            'reload' => ["systemctl reload {$site->php_version->fpmService()}", 'systemctl reload nginx'],
            'healthChecks' => [],
        ]), timeout: 60);

        $site->update(['php_settings' => $settings]);
    }
}
