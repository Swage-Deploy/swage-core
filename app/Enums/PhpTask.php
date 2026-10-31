<?php

namespace App\Enums;

enum PhpTask: string
{
    case Install = 'install';
    case MakeDefault = 'default';
    case Remove = 'remove';

    /**
     * Describe the task while it runs, such as "Installing PHP 8.4".
     */
    public function label(PhpVersion $php): string
    {
        return match ($this) {
            self::Install => __('Installing PHP :version', ['version' => $php->value]),
            self::MakeDefault => __('Making PHP :version the default', ['version' => $php->value]),
            self::Remove => __('Removing PHP :version', ['version' => $php->value]),
        };
    }
}
