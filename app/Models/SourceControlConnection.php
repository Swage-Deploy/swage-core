<?php

namespace App\Models;

use Database\Factories\SourceControlConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's account on a source control provider, with the credentials Swage acts as.
 */
#[Fillable(['provider', 'account_id', 'username', 'token', 'refresh_token', 'expires_at'])]
#[Hidden(['token', 'refresh_token'])]
class SourceControlConnection extends Model
{
    /** @use HasFactory<SourceControlConnectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if the token has expired, or will within a minute, so it should be refreshed before it is used.
     */
    public function tokenHasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now()->addMinute());
    }
}
