<?php

namespace App\Servers;

use App\Models\Server;
use App\Plugins\Attention;
use App\Plugins\HasAttention;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * What the server integrations say needs looking at on servers, for the badges on the servers page and a server's
 * Plugins tab. An integration that fails is left out, so one broken plugin can't take the servers page down with it.
 */
class ServerAttention
{
    /**
     * What each server was found to need, so the pages that ask about one server more than once read it once.
     *
     * @var array<int, array<string, Attention>>
     */
    protected array $servers = [];

    public function __construct(protected ServerIntegrations $integrations) {}

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<int, array<string, Attention>> By server id, then integration id. Servers with nothing are left out.
     */
    public function for(Collection $servers): array
    {
        if ($servers->isEmpty()) {
            return [];
        }

        $attention = [];

        foreach ($this->integrations->all() as $id => $integration) {
            if (! $integration instanceof HasAttention) {
                continue;
            }

            try {
                foreach ($integration->attention($servers) as $serverId => $item) {
                    if ($item instanceof Attention && $item->count > 0) {
                        $attention[$serverId][$id] = $item;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $attention;
    }

    /**
     * Everything that needs looking at on one server, by integration id.
     *
     * @return array<string, Attention>
     */
    public function forServer(Server $server): array
    {
        return $this->servers[$server->id] ??= $this->for(new Collection([$server]))[$server->id] ?? [];
    }

    /**
     * Combine what several integrations say into one badge: the total count, at the most severe of their severities.
     *
     * @param  array<string, Attention>  $items
     */
    public static function combine(array $items): ?Attention
    {
        if ($items === []) {
            return null;
        }

        $mostSevere = collect($items)->sortByDesc(fn (Attention $item): int => $item->rank())->first();

        return new Attention(
            array_sum(array_map(fn (Attention $item): int => $item->count, $items)),
            $mostSevere->severity,
            implode(', ', array_map(fn (Attention $item): string => $item->label, $items)),
        );
    }
}
