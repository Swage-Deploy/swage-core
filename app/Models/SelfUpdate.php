<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\SelfUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'status', 'from_commit', 'from_version', 'to_commit', 'to_version', 'plugin_operation', 'output', 'started_at', 'finished_at'])]
class SelfUpdate extends Model
{
    /** @use HasFactory<SelfUpdateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'plugin_operation' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * Describe what the rebuild was for: an update, or a plugin change.
     */
    public function title(): string
    {
        $operation = $this->plugin_operation;

        return match ($operation['action'] ?? null) {
            // An install from a repository is named after the repository until the plugin's name is known.
            'install' => isset($operation['ref'])
                ? __('Install :plugin at :ref', ['plugin' => $operation['name'] ?? $operation['url'], 'ref' => $operation['ref']])
                : __('Install :plugin', ['plugin' => $operation['name'] ?? $operation['url']]),
            'update' => __('Update :plugins', ['plugins' => implode(', ', $operation['names'])]),
            'remove' => __('Remove :plugin', ['plugin' => $operation['name']]),
            default => __('Update'),
        };
    }

    /**
     * Append a line to the update log.
     */
    public function log(string $text): void
    {
        $this->update(['output' => ($this->output ?? '').$text]);
    }
}
