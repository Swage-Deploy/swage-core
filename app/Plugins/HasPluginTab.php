<?php

namespace App\Plugins;

/**
 * Names a server or site integration's tab on the Plugins page, where each integration has a tab of its own once more
 * than one is installed. Integrations that don't implement it are named after their id.
 */
interface HasPluginTab
{
    /**
     * The tab's name, such as "Security scanner".
     */
    public function tabLabel(): string;

    /**
     * The name of the Heroicon shown on the tab, such as "shield-check".
     */
    public function tabIcon(): string;
}
