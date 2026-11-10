<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * A tool of the read-only MCP server. Each one reads what the panel has already stored and never connects to a
 * server. It acts as the API key's user, so the server and site policies decide what it reaches, and it is only
 * listed when the key may read its area.
 *
 * Secrets are never returned: no .env values, deploy or webhook URLs, deploy keys, provisioning commands or passwords.
 */
abstract class SwageTool extends Tool
{
    /**
     * The ability the API key needs for the tool to be listed, or null when any key may use it.
     */
    protected ?ApiAbility $ability = null;

    public function shouldRegister(): bool
    {
        return $this->ability === null || (bool) Auth::user()?->tokenCan($this->ability->value);
    }

    protected function keyCan(Request $request, ApiAbility $ability): bool
    {
        return (bool) $request->user()?->tokenCan($ability->value);
    }

    protected function findServer(Request $request, mixed $id): ?Server
    {
        $server = is_numeric($id) ? Server::find((int) $id) : null;

        return $server && $request->user()?->can('view', $server) ? $server : null;
    }

    protected function findSite(Request $request, mixed $id): ?Site
    {
        $site = is_numeric($id) ? Site::with('server')->find((int) $id) : null;

        return $site && $request->user()?->can('view', $site) ? $site : null;
    }

    protected function notFound(string $what, mixed $id): Response
    {
        return Response::error("There is no {$what} with ID {$id} that this key can see.");
    }

    protected static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }

    /**
     * The end of a script's output, where the error usually is.
     */
    protected static function tail(?string $output, int $characters = 20000): string
    {
        $output ??= '';

        return mb_strlen($output) > $characters ? '[earlier output cut]'."\n".mb_substr($output, -$characters) : $output;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function usage(?ServerMetric $metric): ?array
    {
        return $metric ? [
            'cpu_percent' => $metric->cpu_percent,
            'load' => [$metric->load_1, $metric->load_5, $metric->load_15],
            'memory_percent' => round($metric->memoryPercent(), 1),
            'memory_used_bytes' => $metric->memory_used,
            'memory_total_bytes' => $metric->memory_total,
            'swap_used_bytes' => $metric->swap_used,
            'swap_total_bytes' => $metric->swap_total,
            'disk_percent' => round($metric->diskPercent(), 1),
            'disk_used_bytes' => $metric->disk_used,
            'disk_total_bytes' => $metric->disk_total,
            'uptime_seconds' => $metric->uptime_seconds,
            'read_at' => self::time($metric->created_at),
        ] : null;
    }
}
