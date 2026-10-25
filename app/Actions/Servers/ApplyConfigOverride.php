<?php

namespace App\Actions\Servers;

use App\Enums\ConfigService;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Provisioning\ServiceConfigFiles;
use App\Ssh\Script;

class ApplyConfigOverride
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Write a service's config with the override, and save the override once the service accepts it.
     *
     * The server keeps its previous config when validation, the reload or the health check fails.
     *
     * @throws ScriptFailedException
     */
    public function handle(Server $server, ConfigService $service, ?string $override): void
    {
        $override = filled($override) ? rtrim(str_replace("\r\n", "\n", $override)) : null;
        $files = ServiceConfigFiles::for($server)->withOverride($service, $override);

        $this->runTask->run($server, "Applying custom {$service->label()} config", Script::render('config.apply', [
            'files' => $files->files($service),
            'validate' => $files->validationCommands($service),
            'reload' => $files->reloadCommands($service),
            'healthChecks' => $files->healthChecks($service),
        ]), timeout: 180);

        $server->update(['config_overrides' => array_filter(
            [...($server->config_overrides ?? []), $service->value => $override],
            fn (?string $value): bool => $value !== null,
        )]);
    }
}
