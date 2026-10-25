<?php

namespace App\Enums;

/**
 * What someone added to a server or site may do there.
 */
enum MemberRole: string
{
    case Manager = 'manager';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => __('Manager'),
            self::Viewer => __('Viewer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manager => __('Can deploy, change settings and the environment, and add or remove members.'),
            self::Viewer => __('Can see deployments, settings and logs, but change nothing. The environment, which holds secrets, stays hidden.'),
        };
    }
}
