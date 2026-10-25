<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Running => 'amber',
            self::Finished => 'green',
            self::Failed => 'red',
        };
    }
}
