<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Enums\PhpVersion;
use App\Enums\TaskStatus;
use App\Models\Certificate;
use App\Models\Site;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetServer extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::ServersRead;

    protected string $description = 'Get a server\'s details: its software, latest usage, sites, whether it needs a reboot and why, certificates that are failing to renew, and its recent failed tasks.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->integer()->description('The server\'s ID, from list-servers.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $server = $this->findServer($request, $request->get('server_id'));

        if (! $server) {
            return $this->notFound('server', $request->get('server_id'));
        }

        $server->load('latestMetric');

        return Response::json([
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type,
            'status' => $server->status,
            'provisioning_step' => $server->isActive() ? null : $server->provisioning_step,
            'ip_address' => $server->ip_address,
            'private_ip_address' => $server->private_ip_address,
            'ssh_port' => $server->ssh_port,
            'os_version' => $server->os_version,
            'cpu_cores' => $server->cpu_cores,
            'memory_mb' => $server->memory_mb,
            'disk_gb' => $server->disk_gb,
            'php_versions' => $server->php_versions?->map(fn (PhpVersion $version): string => $version->value)->values(),
            'default_php_version' => $server->default_php_version,
            'database_engines' => array_column($server->databaseEngines(), 'value'),
            'has_redis' => $server->has_redis,
            'reboot_required' => $server->reboot_required_at ? [
                'since' => self::time($server->reboot_required_at),
                'packages' => $server->reboot_required_packages ?? [],
            ] : null,
            'usage' => self::usage($server->latestMetric),
            'sites' => $this->keyCan($request, ApiAbility::SitesRead)
                ? $server->sites()->orderBy('domain')->get()
                    ->filter(fn (Site $site): bool => $request->user()->can('view', $site))
                    ->map(fn (Site $site): array => ['id' => $site->id, 'domain' => $site->domain, 'status' => $site->status])
                    ->values()
                : null,
            'certificates_not_renewing' => $this->keyCan($request, ApiAbility::CertificatesRead)
                ? Certificate::query()->renewalOverdue()->whereHas('site', fn ($query) => $query->where('server_id', $server->id))->with('site')->get()
                    ->map(fn (Certificate $certificate): array => ['domain' => $certificate->site->domain, 'expires_at' => self::time($certificate->expires_at)])
                : null,
            'recent_failed_tasks' => $server->tasks()->where('status', TaskStatus::Failed)->where('created_at', '>=', now()->subWeek())->latest('id')->limit(5)->get(['id', 'name', 'created_at'])
                ->map(fn (Task $task): array => ['id' => $task->id, 'name' => $task->name, 'at' => self::time($task->created_at)]),
            'provisioned_at' => self::time($server->provisioned_at),
        ]);
    }
}
