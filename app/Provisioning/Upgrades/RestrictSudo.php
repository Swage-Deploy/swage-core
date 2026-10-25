<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;

/**
 * Replaces the wildcard sudo rules, which matched extra arguments such as "stop", with helpers that check them.
 */
final class RestrictSudo implements ServerUpgrade
{
    public const string ID = '2026_10_16_restrict_sudo';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('Restrict sudo rules');
    }

    public function description(): string
    {
        return __('Sites may only reload PHP-FPM and restart their own queue workers, instead of stopping services or restarting other sites\' workers. Custom deploy scripts that call "sudo service" or "sudo supervisorctl" should use swage_reload_php and swage_restart_daemon instead.');
    }

    public function apply(Server $server): void
    {
        $this->runTask->run($server, 'Restricting sudo rules', Script::render('upgrades.restrict-sudo', [
            'isolatedSites' => $server->sites()->whereNotNull('system_user')->get(),
        ]), timeout: 120);
    }
}
