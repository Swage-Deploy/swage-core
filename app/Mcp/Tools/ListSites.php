<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\Site;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class ListSites extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::SitesRead;

    protected string $description = 'List the sites you can see in Swage, on every server or one, with their repository, latest deployment and certificate expiry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->integer()->description('Only list this server\'s sites.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        $sites = Site::query()
            ->where(fn ($query) => $query
                ->whereIn('server_id', $user->accessibleServers()->select('id'))
                ->orWhereIn('id', $user->sharedSites()->select('sites.id')))
            ->when($request->get('server_id'), fn ($query, $serverId) => $query->where('server_id', (int) $serverId))
            ->with('server', 'latestDeployment', 'certificate')
            ->orderBy('domain')
            ->get();

        $deployments = $this->keyCan($request, ApiAbility::DeploymentsRead);
        $certificates = $this->keyCan($request, ApiAbility::CertificatesRead);

        return Response::json($sites->map(fn (Site $site): array => [
            'id' => $site->id,
            'domain' => $site->domain,
            'server' => ['id' => $site->server_id, 'name' => $site->server->name],
            'status' => $site->status,
            'php_version' => $site->php_version,
            'repository' => $site->hasRepository() ? ['source' => $site->source_control, 'repository' => $site->repository, 'branch' => $site->branch] : null,
            'latest_deployment' => $deployments && $site->latestDeployment ? [
                'id' => $site->latestDeployment->id,
                'status' => $site->latestDeployment->status,
                'at' => self::time($site->latestDeployment->created_at),
            ] : null,
            'certificate_expires_at' => $certificates ? self::time($site->certificate?->expires_at) : null,
        ])->all());
    }
}
