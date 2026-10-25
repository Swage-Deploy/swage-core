<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\SiteCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Artisan command run in a site's live release, such as "migrate --force". The command holds what follows
 * "php artisan".
 */
#[Fillable(['user_id', 'command', 'status', 'exit_code', 'output', 'started_at', 'finished_at'])]
class SiteCommand extends Model
{
    /** @use HasFactory<SiteCommandFactory> */
    use HasFactory, Prunable;

    /**
     * How long commands, and the output they hold, are kept.
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
            'exit_code' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<SiteCommand>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
