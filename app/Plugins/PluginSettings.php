<?php

namespace App\Plugins;

use App\Models\PluginSetting;
use App\Servers\ServerIntegration;
use App\Sites\SiteIntegration;
use App\SourceControl\Setting;

/**
 * The values of the settings server and site integrations declare, as entered on their config pages. Server and site
 * integrations may share an id, so each value is stored under the integration's kind as well.
 */
class PluginSettings
{
    /**
     * Values already read, by integration.
     *
     * @var array<string, array<string, string>>
     */
    protected array $loaded = [];

    /**
     * Get a setting's value, or null when it has none.
     */
    public function get(ServerIntegration|SiteIntegration $integration, string $key): ?string
    {
        return $this->all($integration)[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(ServerIntegration|SiteIntegration $integration): array
    {
        $name = static::name($integration);

        return $this->loaded[$name] ??= PluginSetting::where('integration', $name)->get()->pluck('value', 'key')->all();
    }

    /**
     * Determine if every required setting the integration declares has a value.
     */
    public function isConfigured(ServerIntegration|SiteIntegration $integration): bool
    {
        if (! $integration instanceof HasPluginSettings) {
            return true;
        }

        $values = $this->all($integration);

        return collect($integration->settings())->every(fn (Setting $setting): bool => ! $setting->required || filled($values[$setting->key] ?? null));
    }

    /**
     * Save an integration's settings. A null value removes the setting.
     *
     * @param  array<string, string|null>  $values
     */
    public function put(ServerIntegration|SiteIntegration $integration, array $values): void
    {
        $name = static::name($integration);

        foreach ($values as $key => $value) {
            if (blank($value)) {
                PluginSetting::where('integration', $name)->where('key', $key)->delete();
            } else {
                PluginSetting::updateOrCreate(['integration' => $name, 'key' => $key], ['value' => $value]);
            }
        }

        unset($this->loaded[$name]);
    }

    /**
     * The name an integration's settings are stored under, such as "server:security-scanner".
     */
    public static function name(ServerIntegration|SiteIntegration $integration): string
    {
        return ($integration instanceof ServerIntegration ? 'server' : 'site').':'.$integration->id();
    }
}
