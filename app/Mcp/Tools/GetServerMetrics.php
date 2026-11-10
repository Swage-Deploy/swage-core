<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\ServerMetric;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetServerMetrics extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::ServersRead;

    protected string $description = 'Get a server\'s CPU, load, memory and disk use: the latest reading and hourly averages and peaks over a period of up to a week. Swage reads them every five minutes; a missing hour means the server could not be reached.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->integer()->description('The server\'s ID, from list-servers.')->required(),
            'hours' => $schema->integer()->description('How many hours back to summarise, up to 168. Defaults to 24.')->min(1)->max(168),
        ];
    }

    public function handle(Request $request): Response
    {
        $server = $this->findServer($request, $request->get('server_id'));

        if (! $server) {
            return $this->notFound('server', $request->get('server_id'));
        }

        $hours = max(1, min(168, (int) $request->get('hours', 24)));
        $readings = $server->metrics()->where('created_at', '>=', now()->subHours($hours))->orderBy('created_at')->get();

        return Response::json([
            'server' => ['id' => $server->id, 'name' => $server->name, 'cpu_cores' => $server->cpu_cores],
            'latest' => self::usage($readings->last() ?? $server->latestMetric),
            'hourly' => $readings
                ->groupBy(fn (ServerMetric $reading): string => $reading->created_at->copy()->startOfHour()->toIso8601String())
                ->map(fn ($hour, string $start): array => [
                    'hour' => $start,
                    'cpu_percent_average' => round($hour->avg('cpu_percent'), 1),
                    'cpu_percent_peak' => round($hour->max('cpu_percent'), 1),
                    'load_peak' => round($hour->max('load_1'), 2),
                    'memory_percent_average' => round($hour->avg(fn (ServerMetric $reading): float => $reading->memoryPercent()), 1),
                    'memory_percent_peak' => round($hour->max(fn (ServerMetric $reading): float => $reading->memoryPercent()), 1),
                    'disk_percent' => round($hour->last()->diskPercent(), 1),
                    'readings' => $hour->count(),
                ])
                ->values(),
        ]);
    }
}
