<?php

namespace App\Http\Resources;

use App\Models\DatabaseUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A database user, without its password.
 *
 * @mixin DatabaseUser
 */
class DatabaseUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine,
            'username' => $this->username,
            'status' => $this->status,
            'databases' => $this->whenLoaded('databases', fn () => $this->databases->modelKeys()),
            'created_at' => $this->created_at,
        ];
    }
}
