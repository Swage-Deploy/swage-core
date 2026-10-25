<?php

namespace App\Http\Resources;

use App\Models\ScheduledJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledJob
 */
class ScheduledJobResource extends JsonResource
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
            'user' => $this->user,
            'frequency' => $this->frequency,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
