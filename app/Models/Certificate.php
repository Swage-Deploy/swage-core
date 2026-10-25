<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domains', 'status', 'expires_at'])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    /**
     * Certbot renews certificates with 30 days left, so one closer to expiring than this has failed to renew.
     */
    public const int RENEWAL_OVERDUE_DAYS = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'status' => ResourceStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Determine if the certificate is installed and serving traffic.
     */
    public function isActive(): bool
    {
        return $this->status === ResourceStatus::Installed;
    }

    /**
     * Determine if the certificate is installed but has not been renewed when certbot should have renewed it.
     */
    public function renewalIsOverdue(): bool
    {
        return $this->isActive() && $this->expires_at?->lt(now()->addDays(self::RENEWAL_OVERDUE_DAYS)) === true;
    }

    /**
     * Scope to installed certificates that have not been renewed when certbot should have renewed them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function renewalOverdue(Builder $query): void
    {
        $query->where('status', ResourceStatus::Installed)->where('expires_at', '<', now()->addDays(self::RENEWAL_OVERDUE_DAYS));
    }
}
