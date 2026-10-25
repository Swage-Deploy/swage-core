<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Models\Server;
use App\Ssh\Script;

/**
 * Turns on unattended upgrades, which Swage did not configure on servers it provisioned earlier. Most Ubuntu images
 * already install Ubuntu's own security updates, but never those from the PHP PPA or NodeSource.
 */
final class AutomaticSecurityUpdates implements ServerUpgrade
{
    public const string ID = '2026_10_21_automatic_security_updates';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('Install security updates automatically');
    }

    public function description(): string
    {
        return __('Security updates are installed every day, including PHP and Node.js updates, which Ubuntu leaves out by default. Services restart as their packages update, which interrupts requests for about a second. The server is never rebooted automatically: its overview page shows when an update needs one.');
    }

    public function apply(Server $server): void
    {
        $this->runTask->run($server, 'Turning on automatic security updates', Script::render('upgrades.automatic-security-updates', ['server' => $server]), timeout: 300);
    }
}
