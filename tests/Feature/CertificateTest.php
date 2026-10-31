<?php

use App\Enums\ResourceStatus;
use App\Jobs\ObtainCertificate;
use App\Jobs\RefreshCertificateExpiry;
use App\Jobs\RemoveCertificate;
use App\Models\Certificate;
use App\Models\Site;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('obtaining a certificate switches the site\'s APP_URL to https', function () {
    $shell = fakeShell()->respondTo('certbot certonly', new ScriptResult(0, 'SWAGE_EXPIRES Jan 13 12:00:00 2027 GMT'));
    $site = Site::factory()->systemUser('example')->create(['domain' => 'example.com', 'aliases' => ['www.example.com']]);
    $certificate = Certificate::factory()->for($site)->create(['domains' => ['example.com', 'www.example.com'], 'status' => ResourceStatus::Pending]);

    ObtainCertificate::dispatchSync($certificate);

    expect($certificate->fresh()->status)->toBe(ResourceStatus::Installed);

    $script = $shell->lastScript();

    expect($script)
        ->toContain("ENV_FILE='/home/example/example.com/shared/.env'")
        ->toContain('http://(example\.com|www\.example\.com)')
        ->toContain('#APP_URL=\1https://\2\3#')
        ->toContain('sudo -u example sed -i -E')
        ->toContain("sudo -u example -H php8.5 '/home/example/example.com/current/artisan' config:cache")
        ->toContain('systemctl reload php8.5-fpm');

    expect(strpos($script, 'certbot certonly'))->toBeLessThan(strpos($script, 'Set APP_URL to https://'));
});

test('octane sites reload their workers after APP_URL changes', function () {
    $shell = fakeShell();
    $site = Site::factory()->inPlace()->create(['octane' => true, 'octane_port' => 8001]);
    $certificate = Certificate::factory()->for($site)->create(['status' => ResourceStatus::Pending]);

    ObtainCertificate::dispatchSync($certificate);

    expect($shell->lastScript())->toContain("artisan' octane:reload --server=frankenphp");
});

test('removing a certificate switches the site\'s APP_URL back to http', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['domain' => 'example.com']);
    $certificate = Certificate::factory()->for($site)->create();

    RemoveCertificate::dispatchSync($certificate);

    expect($shell->lastScript())
        ->toContain('https://(example\.com)')
        ->toContain('#APP_URL=\1http://\2\3#');
});

test('certificates due for renewal have their new expiry date read from the server', function () {
    Queue::fake();
    $due = Certificate::factory()->create(['expires_at' => now()->addDays(10)]);
    Certificate::factory()->create(['expires_at' => now()->addDays(80)]);
    Certificate::factory()->create(['expires_at' => now()->addDays(5), 'status' => ResourceStatus::Failed]);

    $this->artisan('swage:refresh-certificates')->assertSuccessful();

    Queue::assertPushed(RefreshCertificateExpiry::class, 1);
    Queue::assertPushed(RefreshCertificateExpiry::class, fn (RefreshCertificateExpiry $job) => $job->certificates->modelKeys() === [$due->id]);
});

test('a renewed certificate gets the expiry date certbot gave it', function () {
    $certificate = Certificate::factory()->create(['expires_at' => now()->addDays(10)]);
    $domain = $certificate->site->domain;
    $shell = fakeShell()->respondTo("/etc/letsencrypt/live/{$domain}/cert.pem", new ScriptResult(0, "SWAGE_CERT {$domain} Jan 16 12:00:00 2027 GMT\n"));

    RefreshCertificateExpiry::dispatchSync($certificate->site->server, $certificate->newCollection([$certificate]));

    expect($certificate->fresh()->expires_at->toDateTimeString())->toBe('2027-01-16 12:00:00');
    $shell->assertRan('openssl x509 -enddate -noout -in "$f"');
});

test('built assets inherit the site\'s security headers', function () {
    $site = Site::factory()->create();

    $config = Script::render('sites.nginx', ['site' => $site, 'certificate' => Certificate::factory()->for($site)->create()]);

    expect(Str::between($config, 'location ^~ /build/ {', '}'))->not->toContain('add_header')
        ->and($config)->toContain('add_header Strict-Transport-Security')
        ->toContain('add_header X-Frame-Options "SAMEORIGIN";');
});

test('the ssl page explains which Cloudflare SSL mode to use', function () {
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.ssl', ['server' => $site->server, 'site' => $site])
        ->assertSee('Behind Cloudflare')
        ->assertSee('Full (strict)');
});

test('a certificate that certbot should already have renewed is overdue', function (string $status, int $daysLeft, bool $overdue) {
    $certificate = Certificate::factory()->create(['status' => $status, 'expires_at' => now()->addDays($daysLeft)]);

    expect($certificate->renewalIsOverdue())->toBe($overdue)
        ->and(Certificate::query()->renewalOverdue()->exists())->toBe($overdue);
})->with([
    'freshly renewed' => [ResourceStatus::Installed->value, 89, false],
    'due for renewal' => [ResourceStatus::Installed->value, 25, false],
    'past renewal' => [ResourceStatus::Installed->value, 10, true],
    'expired' => [ResourceStatus::Installed->value, -3, true],
    'being removed' => [ResourceStatus::Removing->value, 10, false],
]);

test('the ssl page warns when a certificate has not renewed', function () {
    $site = Site::factory()->create();
    $certificate = Certificate::factory()->for($site)->create(['expires_at' => now()->addDays(10)]);

    Livewire::actingAs($site->server->user)->test('pages::sites.ssl', ['server' => $site->server, 'site' => $site])
        ->assertSee('This certificate has not renewed');

    $certificate->update(['expires_at' => now()->addDays(60)]);

    Livewire::actingAs($site->server->user)->test('pages::sites.ssl', ['server' => $site->server, 'site' => $site->fresh()])
        ->assertDontSee('This certificate has not renewed');
});

test('the server page lists its sites whose certificates have not renewed', function () {
    $site = Site::factory()->create(['domain' => 'stale.example.com']);
    Certificate::factory()->for($site)->create(['expires_at' => now()->subDay()]);
    Certificate::factory()->for(Site::factory()->for($site->server)->create(['domain' => 'fresh.example.com']))->create();
    Certificate::factory()->for(Site::factory()->create(['domain' => 'elsewhere.example.com']))->create(['expires_at' => now()->addDays(5)]);

    Livewire::actingAs($site->server->user)->test('pages::servers.show', ['server' => $site->server])
        ->assertSee('Certificates not renewing')
        ->assertSee('stale.example.com')
        ->assertSee(route('servers.sites.ssl', [$site->server, $site]))
        ->assertDontSee('fresh.example.com')
        ->assertDontSee('elsewhere.example.com');
});
