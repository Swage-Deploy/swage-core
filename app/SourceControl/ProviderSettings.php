<?php

namespace App\SourceControl;

use App\Models\ProviderSetting;

/**
 * The values of the settings providers declare, as entered on their config pages.
 */
class ProviderSettings
{
    /**
     * Values already read, by provider.
     *
     * @var array<string, array<string, string>>
     */
    protected array $loaded = [];

    /**
     * Get a setting's value, or null when it has none.
     */
    public function get(string $provider, string $key): ?string
    {
        return $this->all($provider)[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(string $provider): array
    {
        return $this->loaded[$provider] ??= ProviderSetting::where('provider', $provider)->get()->pluck('value', 'key')->all();
    }

    /**
     * Save a provider's settings. A null value removes the setting.
     *
     * @param  array<string, string|null>  $values
     */
    public function put(string $provider, array $values): void
    {
        foreach ($values as $key => $value) {
            if (blank($value)) {
                ProviderSetting::where('provider', $provider)->where('key', $key)->delete();
            } else {
                ProviderSetting::updateOrCreate(['provider' => $provider, 'key' => $key], ['value' => $value]);
            }
        }

        unset($this->loaded[$provider]);
    }
}
