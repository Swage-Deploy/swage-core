<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Ssh\Script;

/**
 * Rewrites every site's nginx config so nginx only follows a link in a site's files to something the link's owner also
 * owns.
 *
 * nginx belongs to the group of every site it serves, so it can read files no other site can. Without the check, a site
 * could link to another isolated site's files from its own web directory and have nginx serve them.
 */
final class SiteSymlinkOwnership implements ServerUpgrade
{
    public const string ID = '2026_10_26_site_symlink_ownership';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('Stop sites serving other sites\' files through links');
    }

    public function description(): string
    {
        return __('nginx stops following a link in a site\'s web directory to a file or directory owned by another user, so one site cannot serve another\'s files. Links to the site\'s own files, such as Laravel\'s public/storage, keep working. nginx reloads once.');
    }

    public function apply(Server $server): void
    {
        $sites = $server->sites()->where('status', ResourceStatus::Installed)->with('certificate')->get();

        $this->runTask->run($server, 'Stopping sites serving other sites\' files through links', Script::render('upgrades.site-symlink-ownership', [
            'server' => $server,
            'sites' => $sites,
        ]), timeout: 300);
    }
}
