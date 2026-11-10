<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\Deployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetDeployment extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::DeploymentsRead;

    protected string $description = 'Get a deployment with the output of its deploy script, to find why it failed. Long output is cut to its end, where errors usually are.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'deployment_id' => $schema->integer()->description('The deployment\'s ID, from list-deployments or get-site.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $deployment = is_numeric($id = $request->get('deployment_id')) ? Deployment::find((int) $id) : null;
        $site = $deployment ? $this->findSite($request, $deployment->site_id) : null;

        if (! $site) {
            return $this->notFound('deployment', $id);
        }

        return Response::json([
            'id' => $deployment->id,
            'site' => ['id' => $site->id, 'domain' => $site->domain, 'server' => $site->server->name],
            'status' => $deployment->status,
            'triggered_by' => $deployment->triggered_by,
            'commit' => $deployment->commit_hash ? ['hash' => $deployment->commit_hash, 'message' => $deployment->commit_message, 'author' => $deployment->commit_author] : null,
            'started_at' => self::time($deployment->started_at),
            'finished_at' => self::time($deployment->finished_at),
            'output' => self::tail($deployment->output),
        ]);
    }
}
