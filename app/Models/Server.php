<?php

namespace App\Models;

use App\Enums\ConfigService;
use App\Enums\DatabaseEngine;
use App\Enums\MemberRole;
use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerUpgrades;
use App\Provisioning\Upgrades\RedisAuthentication;
use App\Provisioning\Upgrades\SeparateNginxUser;
use App\Provisioning\Upgrades\ServerUpgrade;
use App\Servers\ServerIntegration;
use App\Servers\ServerIntegrations;
use App\Sites\SiteIntegration;
use App\Sites\SiteIntegrations;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property Collection<int, PhpVersion> $php_versions
 */
#[Fillable([
    'name', 'type', 'ip_address', 'private_ip_address', 'ssh_port', 'status', 'provision_token', 'provisioning_step',
    'cpu_cores', 'memory_mb', 'disk_gb', 'os_version', 'php_versions', 'default_php_version',
    'has_mysql', 'has_postgres', 'has_pgvector', 'installing_database', 'database_access', 'has_redis', 'ssh_private_key', 'ssh_public_key', 'host_key', 'database_password',
    'redis_password', 'tuning', 'config_overrides', 'applied_upgrades', 'reboot_required_at', 'reboot_required_packages', 'provisioned_at',
])]
#[Hidden(['provision_token', 'ssh_private_key', 'database_password', 'redis_password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'app',
    ];

    /**
     * How long a provisioning server may go without reporting progress before it is treated as stalled.
     */
    public const int PROVISIONING_STALL_MINUTES = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'status' => ServerStatus::class,
            'php_versions' => AsEnumCollection::of(PhpVersion::class),
            'default_php_version' => PhpVersion::class,
            'has_mysql' => 'boolean',
            'has_postgres' => 'boolean',
            'has_pgvector' => 'boolean',
            'database_access' => 'array',
            'has_redis' => 'boolean',
            'ssh_private_key' => 'encrypted',
            'database_password' => 'encrypted',
            'redis_password' => 'encrypted',
            'tuning' => 'array',
            'config_overrides' => 'array',
            'applied_upgrades' => 'array',
            'reboot_required_at' => 'datetime',
            'reboot_required_packages' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    /**
     * The custom config the user has saved for a service, if any.
     */
    public function configOverride(ConfigService $service): ?string
    {
        return $this->config_overrides[$service->value] ?? null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * People given access besides the owner, with the role they were given.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'server_members')->withPivot('role')->withTimestamps();
    }

    /**
     * The role a user was given as a member, or null when they are not one.
     */
    public function memberRole(User $user): ?MemberRole
    {
        $role = $this->members()->whereKey($user->id)->value('server_members.role');

        return $role ? MemberRole::tryFrom($role) : null;
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * Get the system users daemons and scheduled jobs may run as: Swage's user, each isolated site's user, then root.
     *
     * @return list<string>
     */
    public function systemUsers(): array
    {
        return [
            config('swage.user'),
            ...$this->sites()->whereNotNull('system_user')->orderBy('system_user')->pluck('system_user'),
            'root',
        ];
    }

    /**
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    /**
     * @return HasMany<DatabaseUser, $this>
     */
    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    /**
     * @return HasMany<Daemon, $this>
     */
    public function daemons(): HasMany
    {
        return $this->hasMany(Daemon::class);
    }

    /**
     * @return HasMany<ScheduledJob, $this>
     */
    public function scheduledJobs(): HasMany
    {
        return $this->hasMany(ScheduledJob::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Determine if the server has finished provisioning.
     */
    public function isActive(): bool
    {
        return $this->status === ServerStatus::Active;
    }

    /**
     * Determine if the server is provisioning but has stopped reporting progress, such as after a reboot or after the
     * script was killed before it could report the failure.
     */
    public function provisioningHasStalled(): bool
    {
        return $this->status === ServerStatus::Provisioning
            && $this->updated_at?->lt(now()->subMinutes(self::PROVISIONING_STALL_MINUTES));
    }

    /**
     * Determine if the server runs nginx and PHP for sites.
     */
    public function isAppServer(): bool
    {
        return $this->type !== ServerType::Database;
    }

    /**
     * Determine if the server only runs databases, for app servers to connect to.
     */
    public function isDatabaseServer(): bool
    {
        return $this->type === ServerType::Database;
    }

    /**
     * Get the address other servers connect to: the private IP address when the server has one.
     */
    public function connectionAddress(): string
    {
        return $this->private_ip_address ?: $this->ip_address;
    }

    /**
     * Get the addresses the databases listen on. App servers keep them on the loopback interface; database servers also
     * listen on their private IP address, or on every interface when they have none, behind the firewall's allowlist.
     *
     * @return list<string>
     */
    public function databaseListenAddresses(): array
    {
        return match (true) {
            $this->isAppServer() => ['127.0.0.1'],
            filled($this->private_ip_address) => ['127.0.0.1', $this->private_ip_address],
            default => ['*'],
        };
    }

    /**
     * Get the address another server connects to this database server from: its private IP address when this server
     * only listens on its own private IP address, or null when it has none to connect from.
     */
    public function databaseAccessAddressFor(Server $client): ?string
    {
        return filled($this->private_ip_address) ? $client->private_ip_address : $client->ip_address;
    }

    /**
     * Get the addresses allowed to connect to a database server's databases.
     *
     * @return list<string>
     */
    public function databaseAccess(): array
    {
        return array_values($this->database_access ?? []);
    }

    /**
     * Determine if the server runs a database server, MySQL, PostgreSQL or both.
     */
    public function hasDatabase(): bool
    {
        return $this->usesMysql() || $this->usesPostgres();
    }

    public function usesMysql(): bool
    {
        return (bool) $this->has_mysql;
    }

    public function usesPostgres(): bool
    {
        return (bool) $this->has_postgres;
    }

    /**
     * Determine if the server runs a given database engine.
     */
    public function runsDatabaseEngine(DatabaseEngine $engine): bool
    {
        return match ($engine) {
            DatabaseEngine::Mysql => $this->usesMysql(),
            DatabaseEngine::Postgres => $this->usesPostgres(),
        };
    }

    /**
     * Get the database engines the server runs, MySQL first.
     *
     * @return list<DatabaseEngine>
     */
    public function databaseEngines(): array
    {
        return array_values(array_filter(DatabaseEngine::cases(), $this->runsDatabaseEngine(...)));
    }

    /**
     * Get the engine new sites and databases use unless another is chosen.
     */
    public function defaultDatabaseEngine(): ?DatabaseEngine
    {
        return $this->databaseEngines()[0] ?? null;
    }

    /**
     * Get the hardware specs reported by the server, if known.
     */
    public function specs(): ?ServerSpecs
    {
        if ($this->cpu_cores === null || $this->memory_mb === null) {
            return null;
        }

        return new ServerSpecs(
            cpuCores: $this->cpu_cores,
            memoryMb: $this->memory_mb,
            diskGb: $this->disk_gb ?? 0,
        );
    }

    /**
     * Determine if a server upgrade has been applied, either by running it or by provisioning after it existed.
     */
    public function hasAppliedUpgrade(string $id): bool
    {
        return in_array($id, $this->applied_upgrades ?? [], true);
    }

    /**
     * Record that a server upgrade has been applied.
     */
    public function markUpgradeApplied(string $id): void
    {
        if (! $this->hasAppliedUpgrade($id)) {
            $this->update(['applied_upgrades' => [...($this->applied_upgrades ?? []), $id]]);
        }
    }

    /**
     * Get the upgrades this server still needs, in the order they run.
     *
     * @return array<string, ServerUpgrade>
     */
    public function pendingUpgrades(): array
    {
        return ServerUpgrades::pendingFor($this);
    }

    /**
     * Get the user nginx runs as, which owns the PHP-FPM sockets.
     *
     * Servers provisioned before nginx had a user of its own keep running it as Swage's user until upgraded.
     */
    public function webUser(): string
    {
        return $this->hasAppliedUpgrade(SeparateNginxUser::ID) ? 'www-data' : config('swage.user');
    }

    /**
     * Determine if Redis requires the password Swage gives the server's shared sites.
     */
    public function usesRedisAuthentication(): bool
    {
        return $this->has_redis && filled($this->redis_password) && $this->hasAppliedUpgrade(RedisAuthentication::ID);
    }

    /**
     * Secrets that must never be stored in task or deployment output.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return collect([
            $this->database_password,
            $this->redis_password,
            ...$this->databaseUsers()->get()->pluck('password'),
            ...collect(app(ServerIntegrations::class)->all())->flatMap(fn (ServerIntegration $integration): array => $integration->secrets($this)),
            ...collect(app(SiteIntegrations::class)->all())->flatMap(fn (SiteIntegration $integration): array => $integration->secrets($this)),
        ])->filter(fn (?string $secret): bool => is_string($secret) && strlen($secret) >= 8)->unique()->values()->all();
    }

    /**
     * Replace the server's secrets in script output before it is stored.
     *
     * @param  list<string>|null  $secrets  The server's secrets, when already loaded.
     */
    public function redactSecrets(string $output, ?array $secrets = null): string
    {
        $secrets ??= $this->secrets();

        return $secrets === [] ? $output : str_replace($secrets, '********', $output);
    }

    /**
     * Get the one-line command the user runs as root to begin provisioning.
     */
    public function provisionCommand(): string
    {
        $url = route('provision.script', $this->provision_token);

        // Minimal images can lack curl, which fetches the script here and reports progress from it, so it is installed first.
        return "(command -v curl > /dev/null || (apt-get update -qq && apt-get install -yqq curl > /dev/null)) && curl -fsSL '{$url}' -o /tmp/swage-bootstrap.sh && bash /tmp/swage-bootstrap.sh";
    }
}
