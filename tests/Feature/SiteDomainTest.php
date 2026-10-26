<?php

use App\Enums\MemberRole;
use App\Enums\ResourceStatus;
use App\Jobs\ObtainCertificate;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Sites\SiteNginxConfig;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('changing the domain keeps the old one as an alias and leaves the site\'s files where they are', function () {
    Bus::fake();
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com', 'aliases' => ['www.example.com']]);

    Livewire::actingAs($site->server->user)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->set('domain', 'Example.org')
        ->call('changeDomain')
        ->assertHasNoErrors()
        ->assertSet('aliases', 'example.com, www.example.com');

    expect($site->fresh())
        ->domain->toBe('example.org')
        ->aliases->toBe(['example.com', 'www.example.com'])
        ->directory->toBe('example.com')
        ->path()->toBe('/home/example/example.com')
        ->status->toBe(ResourceStatus::Installing);

    expect(SiteNginxConfig::overridePath($site->fresh()))->toBe('/etc/nginx/swage/sites/example.com.conf');

    Bus::assertChained([fn (UpdateSiteConfiguration $job) => $job->previousDomain === 'example.com']);
});

test('the old domain can be dropped, and an alias can become the domain', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain' => 'example.com', 'aliases' => ['www.example.com']]);

    Livewire::actingAs($site->server->user)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->set('domain', 'www.example.com')
        ->set('keepOldDomain', false)
        ->call('changeDomain')
        ->assertHasNoErrors();

    expect($site->fresh())->domain->toBe('www.example.com')->aliases->toBe([]);
});

test('a domain another site on the server uses cannot be taken', function () {
    $server = Server::factory()->create();
    Site::factory()->for($server)->create(['domain' => 'shop.com', 'aliases' => ['www.shop.com']]);
    $site = Site::factory()->for($server)->create(['domain' => 'example.com']);

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->set('domain', 'www.shop.com')
        ->call('changeDomain')
        ->assertHasErrors(['domain' => 'www.shop.com is already used by shop.com on this server.'])
        ->set('domain', 'not a domain')
        ->call('changeDomain')
        ->assertHasErrors(['domain' => 'Enter a valid domain name, such as example.com.'])
        ->set('domain', 'example.com')
        ->call('changeDomain')
        ->assertHasErrors(['domain']);

    expect($site->fresh()->domain)->toBe('example.com');
});

test('a site with a certificate gets a new one covering the new domain, after nginx answers to it', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain' => 'example.com', 'aliases' => ['www.example.com', '*.example.com']]);
    $certificate = Certificate::factory()->for($site)->create(['domains' => ['example.com', 'www.example.com'], 'status' => ResourceStatus::Installed]);

    Livewire::actingAs($site->server->user)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->set('domain', 'example.org')
        ->call('changeDomain')
        ->assertHasNoErrors();

    expect($certificate->fresh())
        ->domains->toBe(['example.org', 'example.com', 'www.example.com'])
        ->status->toBe(ResourceStatus::Installed);

    Bus::assertChained([UpdateSiteConfiguration::class, fn (ObtainCertificate $job) => $job->certificate->is($certificate)]);
});

test('the domain cannot change while a certificate is being obtained', function () {
    $site = Site::factory()->create(['domain' => 'example.com']);
    Certificate::factory()->for($site)->create(['status' => ResourceStatus::Installing]);

    Livewire::actingAs($site->server->user)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->set('domain', 'example.org')
        ->call('changeDomain')
        ->assertHasErrors(['domain' => 'Wait for the certificate to finish before changing the domain.']);
});

test('viewers cannot change the domain', function () {
    $site = Site::factory()->create(['domain' => 'example.com']);

    $viewer = User::factory()->create();
    $site->server->members()->attach($viewer->id, ['role' => MemberRole::Viewer->value]);

    Livewire::actingAs($viewer)->test('pages::sites.settings', ['server' => $site->server, 'site' => $site])
        ->set('domain', 'example.org')
        ->call('changeDomain')
        ->assertDispatched('toast-show');

    expect($site->fresh()->domain)->toBe('example.com');
});

test('nginx answers to the new domain under the site\'s original names, and APP_URL follows it', function () {
    $shell = fakeShell();
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com']);
    $site->update(['domain' => 'example.org', 'aliases' => ['example.com']]);
    Certificate::factory()->for($site)->create(['domains' => ['example.com'], 'status' => ResourceStatus::Installed]);

    UpdateSiteConfiguration::dispatchSync($site->fresh(), 'example.com');

    expect($shell->lastScript())
        ->toContain('server_name example.org example.com;')
        ->toContain('/etc/nginx/sites-available/example.com')
        ->toContain('ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;')
        ->toContain('access_log /var/log/nginx/example.com-access.log;')
        ->toContain("ENV_FILE='/home/example/example.com/shared/.env'")
        ->toContain('https?://example\.com(')
        ->toContain('#APP_URL=\1\2://example.org\3#');
});

test('a new certificate keeps the name the site\'s first one had', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['domain' => 'example.com']);
    $site->update(['domain' => 'example.org', 'aliases' => ['example.com']]);
    $certificate = Certificate::factory()->for($site)->create(['domains' => ['example.org', 'example.com']]);

    ObtainCertificate::dispatchSync($certificate);

    expect($shell->lastScript())
        ->toContain("--cert-name 'example.com'")
        ->toContain("-d 'example.org'")
        ->toContain('/etc/letsencrypt/live/example.com/cert.pem');
});
