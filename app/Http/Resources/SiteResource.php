<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Site
 */
class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canUpdate = $request->user()->can('update', $this->resource);

        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'domain' => $this->domain,
            'aliases' => $this->aliases ?? [],
            'status' => $this->status,
            'web_directory' => $this->web_directory,
            'php_version' => $this->php_version,
            'system_user' => $this->systemUser(),
            'zero_downtime' => $this->zero_downtime,
            'octane' => $this->octane,
            'private_redis' => $this->private_redis,
            'repository' => $this->hasRepository() ? [
                'source' => $this->source_control,
                'repository' => $this->repository,
                'branch' => $this->branch,
                // A custom repository's deploy key is added to it by hand, so it is shown to whoever installs one.
                'deploy_key' => $this->when($canUpdate && $this->usesCustomRepository(), $this->deploy_key_public),
            ] : null,
            'quick_deploy' => $this->quick_deploy,
            'deploy_trigger_url' => $this->when($canUpdate, fn (): string => $this->deployTriggerUrl()),
            'latest_deployment' => new DeploymentResource($this->whenLoaded('latestDeployment')),
            'certificate' => new CertificateResource($this->whenLoaded('certificate')),
            'can' => [
                'update' => $canUpdate,
                'delete' => $request->user()->can('delete', $this->resource),
                'use_source_control' => $request->user()->can('useSourceControl', $this->resource),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
