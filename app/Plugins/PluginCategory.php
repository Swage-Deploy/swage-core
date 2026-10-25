<?php

namespace App\Plugins;

/**
 * Where a plugin's settings live, following what it extends: Swage as a whole, each server, or each site.
 */
enum PluginCategory: string
{
    case System = 'system';
    case Server = 'server';
    case Site = 'site';

    public function label(): string
    {
        return match ($this) {
            self::System => __('System'),
            self::Server => __('Server'),
            self::Site => __('Site'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::System => 'cog-8-tooth',
            self::Server => 'server',
            self::Site => 'globe-alt',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System => __('System plugins extend Swage as a whole, such as the git hosts sites install repositories from. Their settings are on this tab.'),
            self::Server => __('Server plugins add services each server can be set up with. Their settings are on each server\'s Plugins tab.'),
            self::Site => __('Site plugins connect sites to other services, such as monitoring. Their settings are on each site\'s Plugins tab.'),
        };
    }
}
