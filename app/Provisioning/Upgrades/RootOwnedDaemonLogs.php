<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Models\Daemon;
use App\Models\Server;
use App\Ssh\Script;

/**
 * Moves daemons' logs out of Swage's user's home. Supervisor opens them as root, so code running as Swage's user
 * could swap a log for a link and have root write to any file.
 */
final class RootOwnedDaemonLogs implements ServerUpgrade
{
    public const string ID = '2026_10_18_root_owned_daemon_logs';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('Keep daemon logs where only root can write');
    }

    public function description(): string
    {
        return __('Daemon and queue worker logs move to :directory, so sites can no longer trick Supervisor into writing to other files. Every daemon restarts once, and earlier output stays behind in the old log files.', ['directory' => Daemon::LOG_DIRECTORY]);
    }

    public function apply(Server $server): void
    {
        // Render the configs as an upgraded server's, without recording the upgrade until it has succeeded.
        $upgraded = (clone $server)->forceFill([
            'applied_upgrades' => [...($server->applied_upgrades ?? []), self::ID],
        ]);

        $daemons = $server->daemons()->get()->each(fn (Daemon $daemon) => $daemon->setRelation('server', $upgraded));

        $this->runTask->run($server, 'Moving daemon logs', Script::render('upgrades.root-owned-daemon-logs', [
            'daemons' => $daemons,
        ]), timeout: 300);
    }
}
