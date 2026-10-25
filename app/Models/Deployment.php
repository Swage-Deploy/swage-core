<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author',
    'output', 'started_at', 'finished_at',
])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, Prunable;

    /**
     * How long deployments, and the output they hold, are kept.
     */
    public const int RETENTION_DAYS = 90;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * Deployments are kept for 90 days, except each site's latest, which its pages show however old it is.
     *
     * @return Builder<Deployment>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->whereNotIn('id', static::query()->selectRaw('max(id)')->groupBy('site_id'));
    }

    /**
     * Get the abbreviated commit hash.
     */
    public function shortHash(): ?string
    {
        return $this->commit_hash ? substr($this->commit_hash, 0, 7) : null;
    }

    /**
     * Get how long the deployment took, in seconds.
     */
    public function durationInSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
