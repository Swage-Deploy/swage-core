<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Running => 'amber',
            self::Finished => 'green',
            self::Failed => 'red',
        };
    }

    /**
     * Determine if the deployment has not completed yet.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Running]);
    }
}
