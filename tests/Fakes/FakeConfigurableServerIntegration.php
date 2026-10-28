<?php

namespace Tests\Fakes;

use App\Plugins\HasPluginSettings;
use App\SourceControl\Setting;

/**
 * A server integration with settings, standing in for a plugin's integration that needs an API key.
 */
class FakeConfigurableServerIntegration extends FakeServerIntegration implements HasPluginSettings
{
    public function settings(): array
    {
        return [
            new Setting('url', 'API URL', type: Setting::URL),
            new Setting('api_key', 'API key', type: Setting::SECRET),
            new Setting('model', 'Model', required: false),
        ];
    }

    public function settingsDescription(): string
    {
        return 'Connect the fake integration to its API.';
    }
}
