<?php

namespace App\Http\Resources;

use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deployment
 */
class DeploymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'commit' => $this->commit_hash ? [
                'hash' => $this->commit_hash,
                'message' => $this->commit_message,
                'author' => $this->commit_author,
            ] : null,
            'output' => $this->whenHas('output'),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
