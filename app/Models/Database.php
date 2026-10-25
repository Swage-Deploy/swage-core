<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use Database\Factories\DatabaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['engine', 'name', 'status'])]
class Database extends Model
{
    /** @use HasFactory<DatabaseFactory> */
    use HasFactory;

    /**
     * Databases MySQL and PostgreSQL keep their own accounts, privileges, statistics and templates in, which Swage never
     * creates, grants or drops. Both engines' names are reserved on every server, so a server's names never depend on
     * its engine.
     *
     * @var list<string>
     */
    public const array SYSTEM_DATABASES = ['mysql', 'sys', 'performance_schema', 'information_schema', 'postgres', 'template0', 'template1'];

    /**
     * The validation rule that keeps a new database from taking a system database's name, in any letter case, or a
     * name starting with "pg_", which PostgreSQL reserves for roles and a site's database user may share its name.
     */
    public static function notSystemDatabaseRule(): string
    {
        return 'not_regex:/^('.implode('|', self::SYSTEM_DATABASES).'|pg_.*)$/i';
    }

    /**
     * Determine if this is one of the database server's own databases.
     */
    public function isSystemDatabase(): bool
    {
        return in_array(strtolower($this->name), self::SYSTEM_DATABASES, true);
    }

    /**
     * Determine if the database can be exported or restored from a file now. Only MySQL databases can, and not while
     * something else is changing them.
     */
    public function isTransferable(): bool
    {
        return $this->engine === DatabaseEngine::Mysql && ! $this->isSystemDatabase() && ! $this->status->isBusy();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsToMany<DatabaseUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseUser::class);
    }
}
