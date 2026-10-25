<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use Database\Factories\ScheduledJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'command', 'user', 'frequency', 'status'])]
class ScheduledJob extends Model
{
    /** @use HasFactory<ScheduledJobFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the path of the cron file on the server.
     */
    public function cronPath(): string
    {
        return "/etc/cron.d/swage-job-{$this->id}";
    }

    /**
     * Get the path of the file the job's output is appended to, in its user's home directory.
     */
    public function logPath(): string
    {
        $home = $this->user === 'root' ? '/root' : "/home/{$this->user}";

        return "{$home}/.swage/scheduled-job-{$this->id}.log";
    }

    /**
     * Get the path of the logrotate config that keeps the job's log from growing forever.
     */
    public function logrotatePath(): string
    {
        return "/etc/logrotate.d/swage-job-{$this->id}";
    }
}
