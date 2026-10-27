<?php

use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Livewire\Livewire;

test('a site\'s php settings are written to its pool and nginx', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['domain' => 'example.com', 'php_version' => PhpVersion::Php85, 'status' => ResourceStatus::Installed]);

    Livewire::actingAs($site->server->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])
        ->set('memory_limit', '1g')
        ->set('upload_max_filesize', '256M')
        ->set('max_execution_time', '120')
        ->set('max_input_vars', '5000')
        ->set('directives', "; a comment\ndisplay_errors = Off\nerror_reporting = E_ALL & ~E_DEPRECATED")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('failureOutput', null)
        ->assertSet('memory_limit', '1G')
        ->assertSet('directives', "display_errors = Off\nerror_reporting = E_ALL & ~E_DEPRECATED");

    expect($shell->lastScript())
        ->toContain("cat > '/etc/php/8.5/fpm/pool.d/example.conf'")
        ->toContain("php_value[memory_limit] = 1G\nphp_value[upload_max_filesize] = 256M\nphp_value[post_max_size] = 256M\nphp_value[max_execution_time] = 120\nphp_value[max_input_vars] = 5000\nphp_value[display_errors] = Off\nphp_value[error_reporting] = E_ALL & ~E_DEPRECATED\n")
        ->toContain('client_max_body_size 256m;')
        ->toContain('fastcgi_read_timeout 130s;')
        ->toContain('php-fpm8.5 -t')
        ->toContain('systemctl reload php8.5-fpm')
        ->toContain('restore')
        ->and($site->fresh()->php_settings)->toBe([
            'memory_limit' => '1G',
            'upload_max_filesize' => '256M',
            'max_execution_time' => 120,
            'max_input_vars' => 5000,
            'directives' => ['display_errors' => 'Off', 'error_reporting' => 'E_ALL & ~E_DEPRECATED'],
        ]);
});

test('clearing every php setting goes back to the server\'s', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['status' => ResourceStatus::Installed, 'php_settings' => ['memory_limit' => '1G']]);

    Livewire::actingAs($site->server->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])
        ->assertSet('memory_limit', '1G')
        ->set('memory_limit', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($shell->lastScript())->not->toContain('php_value[')
        ->and($site->fresh()->php_settings)->toBeNull();
});

test('a short execution time leaves nginx\'s timeout alone', function () {
    $site = Site::factory()->systemUser('example')->create(['php_settings' => ['max_execution_time' => 30]]);

    expect(Script::render('sites.nginx', ['site' => $site, 'certificate' => null]))->not->toContain('fastcgi_read_timeout');
});

test('php settings that cannot go in the pool config are rejected', function (string $field, string $value) {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['status' => ResourceStatus::Installed]);

    Livewire::actingAs($site->server->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);

    $shell->assertNothingRan();
})->with([
    'a size without a number' => ['memory_limit', 'lots'],
    'a size with a newline' => ['upload_max_filesize', "1G\nuser = root"],
    'an execution time over an hour' => ['max_execution_time', '7200'],
    'a line without a value' => ['directives', 'display_errors'],
    'a section header' => ['directives', '[www] = x'],
    'a value that starts a section' => ['directives', 'display_errors = [root]'],
    'a quoted value' => ['directives', 'display_errors = "Off"'],
    'a value with an equals sign' => ['directives', 'display_errors = a=b'],
    'a managed setting' => ['directives', 'post_max_size = 1G'],
]);

test('rejected php settings keep the previous ones', function () {
    fakeShell()->respondTo('php-fpm8.5 -t', new ScriptResult(1, "ERROR: failed to load configuration file\nRestoring the previous configuration."));
    $site = Site::factory()->systemUser('example')->create(['status' => ResourceStatus::Installed, 'php_settings' => ['memory_limit' => '1G']]);

    Livewire::actingAs($site->server->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])
        ->set('memory_limit', '2G')
        ->call('save')
        ->assertSet('failureOutput', "ERROR: failed to load configuration file\nRestoring the previous configuration.");

    expect($site->fresh()->php_settings)->toBe(['memory_limit' => '1G']);
});

test('php settings are not offered to octane sites, which serve requests themselves', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();
    $site = Site::factory()->for($server)->inPlace()->create(['status' => ResourceStatus::Installed, 'octane' => true, 'octane_port' => 8001]);

    Livewire::actingAs($server->user)->test('pages::sites.php', ['server' => $server, 'site' => $site])
        ->assertSee('come from the server')
        ->set('memory_limit', '1G')
        ->call('save');

    $shell->assertNothingRan();
    expect($site->fresh()->php_settings)->toBeNull();
});

test('php settings can only be applied to an installed site', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['status' => ResourceStatus::Installing]);

    Livewire::actingAs($site->server->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])
        ->set('memory_limit', '1G')
        ->call('save');

    $shell->assertNothingRan();
});

test('php settings are kept when swage rewrites the site\'s pool', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['php_settings' => ['upload_max_filesize' => '1G']]);

    UpdateSiteConfiguration::dispatchSync($site);

    expect($shell->lastScript())->toContain('php_value[upload_max_filesize] = 1G')->toContain('client_max_body_size 1g;');

    $tuning = (new ServerTuner)->tune($site->server->specs(), withMysql: true, withRedis: true);

    expect(Script::render('optimize-server', ['server' => $site->server, 'tuning' => $tuning]))->toContain('php_value[upload_max_filesize] = 1G');
});

test('php settings on another user\'s server cannot be changed', function () {
    $site = Site::factory()->systemUser('example')->create(['status' => ResourceStatus::Installed]);

    Livewire::actingAs(Server::factory()->create()->user)->test('pages::sites.php', ['server' => $site->server, 'site' => $site])->assertForbidden();
});

test('php settings have their own tab beside nginx', function () {
    $site = Site::factory()->systemUser('example')->create(['php_settings' => ['memory_limit' => '1G']]);

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.php', [$site->server, $site]))
        ->assertOk()
        ->assertSeeInOrder([route('servers.sites.nginx', [$site->server, $site]), route('servers.sites.php', [$site->server, $site])], escape: false)
        ->assertSee('PHP settings');

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.settings', [$site->server, $site]))
        ->assertOk()
        ->assertDontSee('PHP settings');
});
