<?php

use App\Models\PluginSetting;
use App\Models\User;
use App\Plugins\PluginSettings;
use App\Servers\ServerIntegrations;
use App\Sites\SiteIntegrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeConfigurableServerIntegration;
use Tests\Fakes\FakeServerIntegration;
use Tests\Fakes\FakeSiteIntegration;

function configurableServerIntegration(): FakeConfigurableServerIntegration
{
    return tap(new FakeConfigurableServerIntegration, fn (FakeConfigurableServerIntegration $integration) => app(ServerIntegrations::class)->register($integration));
}

function pluginSettingsAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('plugin config pages are for administrators who have just confirmed their password', function () {
    configurableServerIntegration();
    $url = route('plugins.configure', ['server', 'fake-server-integration']);

    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($admin = pluginSettingsAdmin())->get($url)->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get($url)
        ->assertOk()
        ->assertSee('Configure Fake Server Integration')
        ->assertSee('Connect the fake integration to its API.')
        ->assertSee('Not configured');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins.configure', ['site', 'fake-server-integration']))->assertNotFound();
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins.configure', ['server', 'missing']))->assertNotFound();
});

test('integrations without settings have no config page', function () {
    app(ServerIntegrations::class)->register(new FakeServerIntegration);

    $this->actingAs(pluginSettingsAdmin())->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('plugins.configure', ['server', 'fake-server-integration']))
        ->assertNotFound();
});

test('the plugins page links each configurable integration on its tab', function () {
    configurableServerIntegration();
    $admin = pluginSettingsAdmin();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'server'))
        ->assertOk()
        ->assertSee('Configure Fake Server Integration')
        ->assertSee(route('plugins.configure', ['server', 'fake-server-integration']));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins', 'site'))
        ->assertOk()
        ->assertDontSee(route('plugins.configure', ['server', 'fake-server-integration']));
});

test('settings are saved encrypted, secrets are never sent back and a blank field keeps them', function () {
    $integration = configurableServerIntegration();
    app(PluginSettings::class)->put($integration, ['url' => 'https://api.example.com/v1', 'api_key' => 'sk-page-secret', 'model' => 'page-model']);
    $admin = pluginSettingsAdmin();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('plugins.configure', ['server', 'fake-server-integration']))
        ->assertSee('page-model')
        ->assertDontSee('sk-page-secret');

    Livewire::actingAs($admin)->test('pages::plugins.configure', ['category' => 'server', 'integration' => 'fake-server-integration'])
        ->assertSet('values.api_key', '')
        ->set('values.model', 'gpt-test')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(PluginSettings::class)->all($integration))->toEqualCanonicalizing(['url' => 'https://api.example.com/v1', 'api_key' => 'sk-page-secret', 'model' => 'gpt-test'])
        ->and(app(PluginSettings::class)->isConfigured($integration))->toBeTrue()
        ->and(PluginSetting::where('key', 'api_key')->value('integration'))->toBe('server:fake-server-integration')
        ->and(DB::table('plugin_settings')->where('key', 'api_key')->value('value'))->not->toContain('sk-page-secret');
});

test('required settings must be filled in, URLs must be URLs, and a saved secret can be removed', function () {
    $integration = configurableServerIntegration();
    $admin = pluginSettingsAdmin();

    Livewire::actingAs($admin)->test('pages::plugins.configure', ['category' => 'server', 'integration' => 'fake-server-integration'])
        ->set('values.url', 'not a url')
        ->call('save')
        ->assertHasErrors(['values.url' => 'url', 'values.api_key' => 'required'])
        ->assertHasNoErrors('values.model');

    app(PluginSettings::class)->put($integration, ['url' => 'https://api.example.com/v1', 'api_key' => 'sk-page-secret']);

    Livewire::actingAs($admin)->test('pages::plugins.configure', ['category' => 'server', 'integration' => 'fake-server-integration'])
        ->call('clearSecret', 'api_key')
        ->assertSet('secretsSet.api_key', false)
        ->call('clearSecret', 'url')
        ->assertNotFound();

    expect(app(PluginSettings::class)->isConfigured($integration))->toBeFalse();
});

test('server and site integrations with the same id keep their settings apart', function () {
    $server = new FakeConfigurableServerIntegration;
    $site = new class extends FakeSiteIntegration
    {
        public function id(): string
        {
            return 'fake-server-integration';
        }
    };
    app(SiteIntegrations::class)->register($site);

    app(PluginSettings::class)->put($server, ['model' => 'server-model']);
    app(PluginSettings::class)->put($site, ['model' => 'site-model']);

    expect(app(PluginSettings::class)->get($server, 'model'))->toBe('server-model')
        ->and(app(PluginSettings::class)->get($site, 'model'))->toBe('site-model');
});
