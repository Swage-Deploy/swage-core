<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteIntegration;
use Livewire\Component;
use Livewire\Livewire;

/**
 * An integration standing in for a plugin's. Its settings panel shows the site's domain, and it records the sites it
 * is told were deployed.
 */
class FakeSiteIntegration implements SiteIntegration
{
    /**
     * @var list<int>
     */
    public array $deployedSites = [];

    /**
     * @param  list<string>  $storedSecrets
     */
    public function __construct(public array $storedSecrets = [])
    {
        Livewire::component('fake-integration.settings', new class extends Component
        {
            public Site $site;

            public function render(): string
            {
                return '<div>Fake integration for {{ $site->domain }}</div>';
            }
        });
    }

    public function id(): string
    {
        return 'fake-integration';
    }

    public function settingsComponent(): string
    {
        return 'fake-integration.settings';
    }

    public function secrets(Server $server): array
    {
        return $this->storedSecrets;
    }

    public function deployed(Site $site): void
    {
        $this->deployedSites[] = $site->id;
    }
}
