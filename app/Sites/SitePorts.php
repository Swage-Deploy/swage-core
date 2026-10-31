<?php

namespace App\Sites;

use App\Models\Server;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Cache;

class SitePorts
{
    /**
     * Give the site a local port that no other site on its server uses, for Octane or Reverb, and save it with the
     * given column. Every site runs as its own user, so two sites sharing a port would have one fail to start, or take
     * the other's traffic. Ports are handed out one at a time per server so two sites enabled together cannot both
     * take the same one.
     *
     * @param  Closure(int): void  $save  Saves the port on the site.
     */
    public static function assign(Site $site, int $first, Closure $save): int
    {
        return Cache::lock("site-ports:{$site->server_id}", 10)->block(10, function () use ($site, $first, $save): int {
            $port = self::next($site->server, $first);

            $save($port);

            return $port;
        });
    }

    /**
     * The lowest port from the given one that no site on the server uses.
     */
    public static function next(Server $server, int $first): int
    {
        $taken = self::taken($server);
        $port = $first;

        while (in_array($port, $taken, true)) {
            $port++;
        }

        return $port;
    }

    /**
     * The ports the server's sites use for Octane and Reverb, including ones kept for a site that has turned them off,
     * and any port a daemon on the server is started with, such as a Reverb server added by hand.
     *
     * @return list<int>
     */
    public static function taken(Server $server): array
    {
        $sites = $server->sites()->get(['octane_port', 'reverb_port'])
            ->flatMap(fn (Site $site): array => [$site->octane_port, $site->reverb_port]);

        $daemons = $server->daemons()->pluck('command')
            ->map(fn (string $command): ?int => preg_match('/--port[= ](\d+)/', $command, $match) ? (int) $match[1] : null);

        return $sites->merge($daemons)->filter()->map(fn ($port): int => (int) $port)->unique()->values()->all();
    }
}
