<?php

namespace Tests\Fakes;

use App\SourceControl\HasSettings;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Setting;

/**
 * A provider with settings, standing in for a plugin's provider that needs OAuth credentials.
 */
class FakeConfigurableProvider extends FakeSourceControlProvider implements HasSettings
{
    public function settings(): array
    {
        return [
            new Setting('client_id', 'Client ID', 'From the OAuth app.'),
            new Setting('client_secret', 'Client secret', 'From the OAuth app.', Setting::SECRET),
            new Setting('server_url', 'Server URL', type: Setting::URL, required: false),
        ];
    }

    public function isConfigured(): bool
    {
        $settings = app(ProviderSettings::class);

        return filled($settings->get($this->id(), 'client_id')) && filled($settings->get($this->id(), 'client_secret'));
    }

    public function setupInstructions(): string
    {
        return 'Create an OAuth app with '.route('source-control.callback', $this->id()).' as its callback URL.';
    }
}
