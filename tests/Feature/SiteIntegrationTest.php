<?php

use App\Deployments\DeployScript;
use App\Jobs\DeploySite;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Sites\HooksIntoDeployments;
use App\Sites\SiteIntegration;
use App\Sites\SiteIntegrations;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Fakes\FakeSiteIntegration;

function fakeSiteIntegration(array $secrets = []): FakeSiteIntegration
{
    return tap(new FakeSiteIntegration($secrets), fn (FakeSiteIntegration $integration) => app(SiteIntegrations::class)->register($integration));
}

test('a site\'s plugins tab shows the panel of every integration', function () {
    fakeSiteIntegration();
    $site = Site::factory()->create(['domain' => 'example.com']);

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.plugins', [$site->server, $site]))
        ->assertOk()
        ->assertSee('Fake integration for example.com');

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.settings', [$site->server, $site]))
        ->assertOk()
        ->assertDontSee('Fake integration for example.com');
});

test('a site\'s plugins tab gives each integration a tab, showing one at a time', function () {
    fakeSiteIntegration();
    app(SiteIntegrations::class)->register(new class extends FakeSiteIntegration
    {
        public function __construct()
        {
            Livewire::component('second-site-integration.settings', new class extends Component
            {
                public Site $site;

                public function render(): string
                {
                    return '<div>Second integration for {{ $site->domain }}</div>';
                }
            });
        }

        public function id(): string
        {
            return 'second-integration';
        }

        public function settingsComponent(): string
        {
            return 'second-site-integration.settings';
        }
    });
    $site = Site::factory()->create(['domain' => 'example.com']);

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.plugins', [$site->server, $site]))
        ->assertOk()
        ->assertSeeInOrder(['Fake Integration', 'Second Integration'])
        ->assertSee('Fake integration for example.com')
        ->assertDontSee('Second integration for example.com');

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.plugins', ['server' => $site->server, 'site' => $site, 'plugin' => 'second-integration']))
        ->assertOk()
        ->assertSee('Second integration for example.com')
        ->assertDontSee('Fake integration for example.com');
});

test('a site\'s plugins tab says when no site plugin is installed', function () {
    $site = Site::factory()->create();

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.plugins', [$site->server, $site]))
        ->assertOk()
        ->assertSee('No site plugins are installed')
        ->assertDontSee(route('plugins', 'site'));
});

test('other people cannot open a site\'s plugins tab', function () {
    $site = Site::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('servers.sites.plugins', [$site->server, $site]))
        ->assertForbidden();
});

test('the secrets integrations store are redacted from task output', function () {
    fakeSiteIntegration(['integration-secret-token']);
    $site = Site::factory()->create();

    expect($site->server->redactSecrets('token=integration-secret-token'))->toBe('token=********');
});

test('integrations are told when a deployment finishes', function () {
    $integration = fakeSiteIntegration();
    fakeShell();
    $site = Site::factory()->withRepository()->create();

    DeploySite::dispatchSync(Deployment::factory()->for($site)->create());

    expect($integration->deployedSites)->toBe([$site->id]);
});

test('integrations must have a valid id', function () {
    $integration = Mockery::mock(SiteIntegration::class);
    $integration->allows('id')->andReturn('Not Valid');

    app(SiteIntegrations::class)->register($integration);
})->throws(InvalidArgumentException::class, '[Not Valid] is not a valid site integration id.');

test('integrations can add steps to the deploy script after the release is created and activated', function (bool $zeroDowntime) {
    app(SiteIntegrations::class)->register(new class extends FakeSiteIntegration implements HooksIntoDeployments
    {
        public function afterCreatingRelease(Site $site): string
        {
            return "echo created {$site->domain}";
        }

        public function afterActivatingRelease(Site $site): string
        {
            return $site->domain === 'skipped.com' ? '' : 'echo activated "$SWAGE_COMMIT_HASH"';
        }
    });
    $site = Site::factory()->withRepository()->create(['domain' => 'example.com', 'zero_downtime' => $zeroDowntime]);

    $script = DeployScript::render($site);

    expect($script)->toContain('export SWAGE_COMMIT_HASH=')
        ->and(strpos($script, 'export SWAGE_COMMIT_HASH='))->toBeLessThan(strpos($script, 'echo created example.com'))
        ->and(strpos($script, 'echo created example.com'))->toBeLessThan(strpos($script, 'composer install'))
        ->and(strpos($script, 'php artisan optimize'))->toBeLessThan(strpos($script, 'echo activated "$SWAGE_COMMIT_HASH"'));

    $site->update(['domain' => 'skipped.com']);

    expect(DeployScript::render($site))->toContain('echo created skipped.com')->not->toContain('echo activated');
})->with([
    'zero downtime' => [true],
    'in place' => [false],
]);
