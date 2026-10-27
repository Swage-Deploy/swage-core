<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use Database\Factories\DatabaseUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['engine', 'username', 'password', 'status'])]
#[Hidden(['password'])]
class DatabaseUser extends Model
{
    /** @use HasFactory<DatabaseUserFactory> */
    use HasFactory;

    /**
     * The comment Swage gives the accounts it creates, so it never changes or drops an account it did not create.
     */
    public const string ACCOUNT_COMMENT = 'Managed by Swage';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'engine' => DatabaseEngine::class,
            'status' => ResourceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsToMany<Database, $this>
     */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class);
    }
}
