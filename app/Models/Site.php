<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\SourceControl\GitUrl;
use App\SourceControl\LinksToCommits;
use App\SourceControl\SourceControlProviders;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'domain', 'directory', 'system_user', 'private_redis', 'aliases', 'web_directory', 'php_version', 'status', 'source_control',
    'repository', 'clone_url', 'branch', 'deploy_key_private', 'deploy_key_public', 'deploy_key_id', 'webhook_id',
    'webhook_secret', 'deploy_script', 'quick_deploy', 'deploy_token', 'zero_downtime', 'octane',
    'octane_port', 'nginx_override', 'php_settings', 'install_error',
])]
#[Hidden(['deploy_key_private', 'webhook_secret', 'deploy_token'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'zero_downtime' => true,
        'octane' => false,
        'quick_deploy' => false,
        'private_redis' => false,
    ];

    /**
     * Guard the deployment mode rules no matter where a site is saved from.
     */
    protected static function booted(): void
    {
        static::updating(function (Site $site): void {
            if ($site->isDirty('zero_downtime')) {
                throw new LogicException('The deployment mode of a site cannot be changed after it is created.');
            }

            if ($site->isDirty('system_user')) {
                throw new LogicException('The system user of a site cannot be changed after it is created.');
            }

            if ($site->isDirty('directory')) {
                throw new LogicException('The directory of a site cannot be changed after it is created.');
            }
        });

        static::creating(function (Site $site): void {
            $site->directory ??= $site->domain;
        });

        static::saving(function (Site $site): void {
            if ($site->zero_downtime && $site->octane) {
                throw new LogicException('Octane cannot run on a site that uses zero downtime deployments.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'php_version' => PhpVersion::class,
            'status' => ResourceStatus::class,
            'deploy_key_private' => 'encrypted',
            'deploy_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'quick_deploy' => 'boolean',
            'zero_downtime' => 'boolean',
            'octane' => 'boolean',
            'octane_port' => 'integer',
            'private_redis' => 'boolean',
            'php_settings' => 'array',
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
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * @return HasMany<SiteCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(SiteCommand::class);
    }

    /**
     * People given access besides the owner, with the role they were given.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_members')->withPivot('role')->withTimestamps();
    }

    /**
     * The role a user was given as a member, or null when they are not one.
     */
    public function memberRole(User $user): ?MemberRole
    {
        $role = $this->members()->whereKey($user->id)->value('site_members.role');

        return $role ? MemberRole::tryFrom($role) : null;
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
     * Get every domain the site responds to.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_unique([$this->domain, ...($this->aliases ?? [])]));
    }

    /**
     * Get the system user that owns the site's files and runs its PHP, deployments, workers and scheduler. Each site
     * has a user of its own, walled off from the server's other sites.
     */
    public function systemUser(): string
    {
        return $this->system_user;
    }

    /**
     * Get the home directory of the site's system user.
     */
    public function homePath(): string
    {
        return '/home/'.$this->systemUser();
    }

    /**
     * Get the name of the site's directory, nginx config, logs and certificate on the server. It is the domain the site
     * was created with, and stays the same when the domain changes.
     */
    public function directoryName(): string
    {
        return $this->directory ?? $this->domain;
    }

    /**
     * Get the site's root directory on the server.
     */
    public function path(): string
    {
        return $this->homePath().'/'.$this->directoryName();
    }

    /**
     * Get the unix socket of the site's private Redis server, which only the site's user can reach.
     */
    public function redisSocket(): string
    {
        return "/run/swage-redis-{$this->system_user}/redis.sock";
    }

    /**
     * Get the PHP-FPM socket nginx passes the site's requests to. Each site has a pool of its own, so its code runs as
     * its own user.
     */
    public function fpmSocket(): string
    {
        return "/run/php/php{$this->php_version->value}-fpm-{$this->system_user}.sock";
    }

    /**
     * Get the path of the site's PHP-FPM pool config for a PHP version.
     */
    public function fpmPoolPath(PhpVersion $phpVersion): string
    {
        return "/etc/php/{$phpVersion->value}/fpm/pool.d/{$this->system_user}.conf";
    }

    /**
     * Get the path of the active release.
     */
    public function currentPath(): string
    {
        return $this->path().'/current';
    }

    /**
     * Get the directory nginx serves files from.
     */
    public function webRoot(): string
    {
        return rtrim($this->currentPath().'/'.ltrim($this->web_directory, '/'), '/');
    }

    /**
     * Get the Supervisor process serving the site with Octane, if any.
     */
    public function octaneDaemon(): ?Daemon
    {
        return $this->daemons->first(fn (Daemon $daemon): bool => $daemon->isOctane());
    }

    /**
     * Determine if Octane may be enabled. Octane workers hold the code of the
     * release they booted from, which conflicts with atomic release swaps.
     */
    public function supportsOctane(): bool
    {
        return ! $this->zero_downtime;
    }

    /**
     * Get the path of the site's git deploy key on the server.
     */
    public function deployKeyPath(): string
    {
        return $this->homePath()."/.ssh/swage_site_{$this->id}";
    }

    /**
     * Determine if a repository has been attached to the site.
     */
    public function hasRepository(): bool
    {
        return filled($this->repository);
    }

    /**
     * Determine if the repository was installed from a clone URL, with its deploy key added by hand, rather than
     * through a source control provider.
     */
    public function usesCustomRepository(): bool
    {
        return $this->source_control === SourceControlProviders::CUSTOM;
    }

    /**
     * Determine if the repository's deploy key is in place: added to the repository by its provider, or generated for
     * a custom repository, where the user adds it themselves.
     */
    public function hasDeployKey(): bool
    {
        return $this->usesCustomRepository() ? filled($this->deploy_key_public) : filled($this->deploy_key_id);
    }

    /**
     * Determine if pushes can deploy the site through a webhook, which only providers can create.
     */
    public function supportsQuickDeploy(): bool
    {
        return $this->hasRepository() && ! $this->usesCustomRepository();
    }

    /**
     * Determine if deployments may be queued from outside Swage, by the deploy URL or a push.
     *
     * The repository's deploy key must be in place, and the site must not be on its way out. A site busy updating its
     * nginx config or marked failed by an earlier change still deploys, so a push is never lost to a brief update.
     */
    public function acceptsDeployments(): bool
    {
        return $this->hasRepository()
            && $this->hasDeployKey()
            && $this->status !== ResourceStatus::Removing;
    }

    /**
     * Get the SSH URL the repository is cloned from, recorded when it was installed so deployments never depend on
     * the provider, or its plugin, being installed.
     */
    public function cloneUrl(): string
    {
        return (string) $this->clone_url;
    }

    /**
     * Get the web page of a commit in the site's repository, from its provider when the provider links to commits, or
     * else built from the clone URL. Null when neither knows where the repository's web pages are.
     */
    public function commitUrl(string $commit): ?string
    {
        $provider = $this->source_control ? app(SourceControlProviders::class)->find($this->source_control) : null;

        if ($provider instanceof LinksToCommits && $this->repository) {
            return $provider->commitUrl($this->repository, $commit);
        }

        $repositoryUrl = GitUrl::webUrl($this->cloneUrl());

        return $repositoryUrl ? "{$repositoryUrl}/commit/{$commit}" : null;
    }

    /**
     * Get the URL that triggers a deployment when requested.
     */
    public function deployTriggerUrl(): string
    {
        return route('sites.deploy-trigger', ['site' => $this, 'token' => $this->deploy_token]);
    }
}
