<?php

namespace App\Enums;

enum PhpVersion: string
{
    case Php83 = '8.3';
    case Php84 = '8.4';
    case Php85 = '8.5';

    /**
     * Get the CLI binary name for the version.
     */
    public function binary(): string
    {
        return "php{$this->value}";
    }

    /**
     * Get the FPM service name for the version.
     */
    public function fpmService(): string
    {
        return "php{$this->value}-fpm";
    }

    /**
     * Get the FPM socket path for the version.
     */
    public function fpmSocket(): string
    {
        return "/run/php/php{$this->value}-fpm.sock";
    }

    /**
     * Get the newest supported version.
     */
    public static function latest(): self
    {
        return self::Php85;
    }
}
