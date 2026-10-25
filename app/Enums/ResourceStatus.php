<?php

namespace App\Enums;

enum ResourceStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Installed = 'installed';
    case Removing = 'removing';
    case Restoring = 'restoring';
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
            self::Installing, self::Removing, self::Restoring => 'amber',
            self::Installed => 'green',
            self::Failed => 'red',
        };
    }

    /**
     * Determine if the resource is waiting on a remote operation to finish.
     */
    public function isBusy(): bool
    {
        return in_array($this, [self::Pending, self::Installing, self::Removing, self::Restoring]);
    }
}
