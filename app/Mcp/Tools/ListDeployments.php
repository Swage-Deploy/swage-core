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
class ListDeployments extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::DeploymentsRead;

    protected string $description = 'List a site\'s deployments, newest first, with their status, commit and timing. Use get-deployment for a deployment\'s output.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('The site\'s ID, from list-sites.')->required(),
            'limit' => $schema->integer()->description('How many to list, up to 50. Defaults to 20.')->min(1)->max(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $site = $this->findSite($request, $request->get('site_id'));

        if (! $site) {
            return $this->notFound('site', $request->get('site_id'));
        }

        $deployments = $site->deployments()->latest('id')
            ->limit(max(1, min(50, (int) $request->get('limit', 20))))
            ->get(['id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author', 'started_at', 'finished_at', 'created_at']);

        return Response::json($deployments->map(fn (Deployment $deployment): array => [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'triggered_by' => $deployment->triggered_by,
            'commit' => $deployment->commit_hash ? ['hash' => $deployment->commit_hash, 'message' => $deployment->commit_message, 'author' => $deployment->commit_author] : null,
            'started_at' => self::time($deployment->started_at),
            'finished_at' => self::time($deployment->finished_at),
        ])->all());
    }
}
