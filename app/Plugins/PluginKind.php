<?php

namespace App\Plugins;

/**
 * What a plugin extends, declared in its composer.json as extra.swage.kind.
 */
enum PluginKind: string
{
    case SourceControl = 'source-control';

    case ServerIntegration = 'server-integration';

    case SiteIntegration = 'site-integration';

    public function label(): string
    {
        return match ($this) {
            self::SourceControl => __('Source control'),
            self::ServerIntegration => __('Server integration'),
            self::SiteIntegration => __('Site integration'),
        };
    }

    public function category(): PluginCategory
    {
        return match ($this) {
            self::SourceControl => PluginCategory::System,
            self::ServerIntegration => PluginCategory::Server,
            self::SiteIntegration => PluginCategory::Site,
        };
    }
}
