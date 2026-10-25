<?php

namespace App\Http\Resources;

use App\Models\Daemon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Daemon
 */
class DaemonResource extends JsonResource
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
            'directory' => $this->directory,
            'user' => $this->user,
            'processes' => $this->processes,
            'stop_wait_seconds' => $this->stop_wait_seconds,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
