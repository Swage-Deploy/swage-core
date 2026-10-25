<?php

namespace App\Enums;

enum ServerStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
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
            self::Provisioning => 'amber',
            self::Active => 'green',
            self::Failed => 'red',
        };
    }
}
