<?php

use App\Models\Site;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

function siteEnvironment(Site $site)
{
    return Livewire::actingAs($site->server->user)->test('pages::sites.environment', ['server' => $site->server, 'site' => $site]);
}

test('saving the environment clears the config cache by default', function () {
    $shell = fakeShell();
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com']);

    siteEnvironment($site)
        ->assertSet('clearConfig', true)
        ->set('environment', "APP_NAME=Example\r\nAPP_DEBUG=false\n")
        ->call('save')
        ->assertHasNoErrors();

    expect($shell->lastScript())
        ->toContain("sudo -u example sh -c 'umask 077 && cat > \"\$1\"' sh '/home/example/example.com/shared/.env'")
        ->toContain("APP_NAME=Example\nAPP_DEBUG=false\nSWAGE_ENV_")
        ->toContain("sudo -u example -H php8.5 '/home/example/example.com/current/artisan' config:clear")
        ->toContain('service php8.5-fpm reload');
});

test('the config cache can be left alone when saving the environment', function () {
    $shell = fakeShell();
    $site = Site::factory()->create();

    siteEnvironment($site)
        ->set('environment', 'APP_NAME=Example')
        ->set('clearConfig', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($shell->lastScript())
        ->not->toContain('config:clear')
        ->toContain('fpm reload');
});

test('octane sites reload their workers after the environment is saved', function () {
    $shell = fakeShell();
    $site = Site::factory()->inPlace()->create(['octane' => true, 'octane_port' => 8001]);

    siteEnvironment($site)
        ->set('environment', 'APP_NAME=Example')
        ->call('save');

    expect($shell->lastScript())
        ->toContain("artisan' octane:reload")
        ->not->toContain('fpm reload');
});

test('the environment is only read from the server once revealed', function () {
    $shell = fakeShell()->respondTo('.env', new ScriptResult(0, "APP_KEY=base64:secret-key\n"));
    $site = Site::factory()->create();

    $page = siteEnvironment($site)
        ->assertSee('Reveal environment')
        ->assertDontSee('secret-key')
        ->assertSet('environment', null);

    expect($shell->ran)->toBe([]);

    $page->call('reveal')
        ->assertSet('environment', "APP_KEY=base64:secret-key\n")
        ->assertSee('Hide')
        ->call('hide')
        ->assertSet('environment', null)
        ->assertDontSee('secret-key');
});

test('a failed read is shown with the option to try again', function () {
    fakeShell()->respondTo('.env', new ScriptResult(1, 'No such file or directory'));

    siteEnvironment(Site::factory()->create())
        ->call('reveal')
        ->assertSet('environment', null)
        ->assertSee('Unable to read the environment file')
        ->assertSee('No such file or directory')
        ->assertSee('Reveal environment');
});

test('the environment is edited on the site\'s settings page, below the site settings', function () {
    $site = Site::factory()->create();

    $this->actingAs($site->server->user)->get(route('servers.sites.settings', [$site->server, $site]))
        ->assertOk()
        ->assertSeeInOrder(['Site settings', 'Environment', 'Reveal environment']);

    expect(Route::has('servers.sites.environment'))->toBeFalse();
});
