<?php

namespace App\SourceControl;

/**
 * A provider with settings administrators enter on its config page, such as OAuth credentials. Providers read the
 * values with ProviderSettings:
 *
 *     app(ProviderSettings::class)->get($this->id(), 'client_id');
 *
 * Optional, so providers written before it existed keep working without it.
 */
interface HasSettings
{
    /**
     * @return list<Setting>
     */
    public function settings(): array;
}
