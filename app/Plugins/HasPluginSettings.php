<?php

namespace App\Plugins;

use App\SourceControl\Setting;

/**
 * A server or site integration with settings administrators enter on its config page, reached from the Plugins page,
 * such as an API key. The settings apply to the whole install, not one server or site. Integrations read the values
 * with PluginSettings:
 *
 *     app(PluginSettings::class)->get($this, 'api_key');
 *
 * Optional, so integrations written before it existed keep working without it.
 */
interface HasPluginSettings
{
    /**
     * @return list<Setting>
     */
    public function settings(): array;

    /**
     * What the settings are for, shown above them on the config page.
     */
    public function settingsDescription(): string;
}
