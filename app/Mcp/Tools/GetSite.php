<?php

namespace App\Mcp\Tools;

use App\Deployments\DeployScript;
use App\Enums\ApiAbility;
use App\Models\Deployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetSite extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::SitesRead;

    protected string $description = 'Get a site\'s settings, repository, certificate, recent deployments and deploy script. Its .env file is never included.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('The site\'s ID, from list-sites.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $site = $this->findSite($request, $request->get('site_id'));

        if (! $site) {
            return $this->notFound('site', $request->get('site_id'));
        }

        $deployments = $this->keyCan($request, ApiAbility::DeploymentsRead);
        $certificate = $this->keyCan($request, ApiAbility::CertificatesRead) ? $site->certificate : null;

        return Response::json([
            'id' => $site->id,
            'domain' => $site->domain,
            'aliases' => $site->aliases ?? [],
            'server' => ['id' => $site->server_id, 'name' => $site->server->name],
            'status' => $site->status,
            'install_error' => $site->install_error,
            'web_directory' => $site->web_directory,
            'php_version' => $site->php_version,
            'system_user' => $site->systemUser(),
            'zero_downtime' => $site->zero_downtime,
            'octane' => $site->octane,
            'reverb' => $site->reverb,
            'repository' => $site->hasRepository() ? ['source' => $site->source_control, 'repository' => $site->repository, 'branch' => $site->branch] : null,
            'quick_deploy' => $site->quick_deploy,
            'certificate' => $certificate ? [
                'status' => $certificate->status,
                'domains' => $certificate->domains,
                'expires_at' => self::time($certificate->expires_at),
            ] : null,
            'recent_deployments' => $deployments
                ? $site->deployments()->latest('id')->limit(5)->get(['id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'created_at', 'finished_at'])
                    ->map(fn (Deployment $deployment): array => [
                        'id' => $deployment->id,
                        'status' => $deployment->status,
                        'triggered_by' => $deployment->triggered_by,
                        'commit' => $deployment->commit_hash ? mb_substr($deployment->commit_hash, 0, 12).' '.$deployment->commit_message : null,
                        'at' => self::time($deployment->created_at),
                    ])
                : null,
            'deploy_script' => $deployments ? ($site->deploy_script ?? DeployScript::default()) : null,
        ]);
    }
}
