<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Certificate
 */
class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domains' => $this->domains,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'renewal_overdue' => $this->renewalIsOverdue(),
            'created_at' => $this->created_at,
        ];
    }
}
