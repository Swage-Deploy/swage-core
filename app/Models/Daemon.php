<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use Database\Factories\DaemonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'command', 'directory', 'user', 'processes', 'stop_wait_seconds', 'status'])]
class Daemon extends Model
{
    /** @use HasFactory<DaemonFactory> */
    use HasFactory;

    /**
     * Where Supervisor writes daemons' output. Only root may write to it.
     */
    public const string LOG_DIRECTORY = '/var/log/swage';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceStatus::class,
            'processes' => 'integer',
            'stop_wait_seconds' => 'integer',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Determine if the daemon is a site's Octane server.
     */
    public function isOctane(): bool
    {
        return str_contains($this->command, 'artisan octane:start');
    }

    /**
     * Get the Supervisor program name.
     */
    public function programName(): string
    {
        return "daemon-{$this->id}";
    }

    /**
     * Get the path of the Supervisor configuration file.
     */
    public function configPath(): string
    {
        return "/etc/supervisor/conf.d/{$this->programName()}.conf";
    }

    /**
     * Get the path of the file Supervisor writes the process's output to.
     */
    public function logPath(): string
    {
        return self::LOG_DIRECTORY."/{$this->programName()}.log";
    }
}
