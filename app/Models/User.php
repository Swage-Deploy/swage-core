<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * API keys belong to the user, so they stop working when the user is removed.
     */
    protected static function booted(): void
    {
        static::deleting(fn (User $user) => $user->tokens()->delete());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Servers the user was added to as a member.
     *
     * @return BelongsToMany<Server, $this>
     */
    public function memberServers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_members')->withPivot('role')->withTimestamps();
    }

    /**
     * Sites the user was added to as a member.
     *
     * @return BelongsToMany<Site, $this>
     */
    public function memberSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_members')->withPivot('role')->withTimestamps();
    }

    /**
     * The servers the user can open: their own and those they are a member of, or every server for an administrator.
     *
     * @return Builder<Server>
     */
    public function accessibleServers(): Builder
    {
        if ($this->is_admin) {
            return Server::query();
        }

        return Server::query()->where(fn (Builder $query) => $query
            ->where('user_id', $this->id)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($this->id)));
    }

    /**
     * Sites the user is a member of on servers they cannot open, which they reach on their own.
     *
     * @return Builder<Site>
     */
    public function sharedSites(): Builder
    {
        return Site::query()
            ->whereHas('members', fn (Builder $members) => $members->whereKey($this->id))
            ->whereNotIn('server_id', $this->accessibleServers()->select('id'));
    }

    /**
     * @return HasMany<SourceControlConnection, $this>
     */
    public function sourceControlConnections(): HasMany
    {
        return $this->hasMany(SourceControlConnection::class);
    }

    /**
     * Get the user's account on a source control provider, if they connected one.
     */
    public function sourceControlConnection(string $provider): ?SourceControlConnection
    {
        return $this->sourceControlConnections()->firstWhere('provider', $provider);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
