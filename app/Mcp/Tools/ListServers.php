<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\Server;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class ListServers extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::ServersRead;

    protected string $description = 'List the servers you can see in Swage with their status, hardware, latest CPU, memory and disk use, and whether they need a reboot.';

    public function handle(Request $request): Response
    {
        $servers = $request->user()->accessibleServers()->with('latestMetric')->withCount('sites')->orderBy('name')->get();

        return Response::json($servers->map(fn (Server $server): array => [
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type,
            'status' => $server->status,
            'ip_address' => $server->ip_address,
            'cpu_cores' => $server->cpu_cores,
            'memory_mb' => $server->memory_mb,
            'disk_gb' => $server->disk_gb,
            'default_php_version' => $server->default_php_version,
            'sites' => $server->sites_count,
            'reboot_required' => $server->reboot_required_at !== null,
            'usage' => self::usage($server->latestMetric),
        ])->all());
    }
}
