<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Enums\ConfigService;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServiceConfigFiles;
use App\Ssh\Script;
use RuntimeException;

/**
 * Moves nginx and the PHP-FPM sockets from Swage's user to www-data, so code running as Swage's user can no longer
 * reach isolated sites' PHP-FPM pools or read their files.
 */
final class SeparateNginxUser implements ServerUpgrade
{
    public const string ID = '2026_10_16_separate_nginx_user';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('Run nginx as its own user');
    }

    public function description(): string
    {
        return __('nginx moves to www-data and owns the PHP-FPM sockets, so sites that are not isolated can no longer run code as, or read the files of, isolated sites. PHP-FPM restarts once, which interrupts requests for about a second.');
    }

    public function apply(Server $server): void
    {
        if (! $server->tuning) {
            throw new RuntimeException("Re-optimize {$server->name} before upgrading it.");
        }

        // Render the configs as an upgraded server's, without recording the upgrade until it has succeeded.
        $upgraded = (clone $server)->forceFill([
            'applied_upgrades' => [...($server->applied_upgrades ?? []), self::ID],
        ]);

        $configFiles = ServiceConfigFiles::for($upgraded);
        $isolatedSites = $server->sites()->whereNotNull('system_user')->get()
            ->each(fn (Site $site) => $site->setRelation('server', $upgraded));

        $this->runTask->run($server, 'Moving nginx to its own user', Script::render('upgrades.separate-nginx-user', [
            'server' => $upgraded,
            'nginxFiles' => $configFiles->files(ConfigService::Nginx),
            'poolFiles' => $configFiles->files(ConfigService::PhpFpmPool),
            'isolatedSites' => $isolatedSites,
            'sharedDaemons' => $server->daemons()->where('user', config('swage.user'))->get(),
        ]), timeout: 300);
    }
}
