<?php

namespace App\Plugins;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;

/**
 * A server integration that can say when a server has something to look at, such as open alerts. Swage shows it as a
 * badge on the server's card on the servers page, on the server's Plugins tab and on the integration's own tab.
 *
 * Optional, so integrations written before it existed keep working without it.
 */
interface HasAttention
{
    /**
     * What needs looking at on each of the servers. The servers page asks about every server it lists at once, so
     * read them together rather than one at a time.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int, Attention> Keyed by server id. Servers with nothing to look at are left out.
     */
    public function attention(Collection $servers): array;
}
