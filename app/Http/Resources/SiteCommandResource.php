<?php

namespace App\Http\Resources;

use App\Models\SiteCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SiteCommand
 */
class SiteCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'command' => $this->command,
            'status' => $this->status,
            'exit_code' => $this->exit_code,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'output' => $this->whenHas('output'),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
