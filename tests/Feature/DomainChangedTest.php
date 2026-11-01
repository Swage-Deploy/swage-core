<?php

use App\Models\Site;
use App\Models\SourceControlConnection;

test('changing domain moves every quick deploy webhook to the new url and lists what to update by hand', function () {
    $provider = fakeSourceControl();
    $site = Site::factory()->withRepository()->create(['repository' => 'team/api', 'quick_deploy' => true, 'webhook_id' => 'hook-old', 'webhook_secret' => 'old-secret']);
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'github']);
    Site::factory()->withRepository()->for($site->server)->create();

    $this->artisan('swage:domain-changed', ['--from' => 'old.example.com'])
        ->expectsOutputToContain('Swage moved from old.example.com to '.config('app.url'))
        ->expectsOutputToContain('Deployment trigger URLs called from CI')
        ->expectsOutputToContain('Moved 1 webhook(s) to the new domain.')
        ->assertSuccessful();

    $site->refresh();

    expect($provider->client->calls[0])->toBe(['deleteWebhook', ['team/api', 'hook-old']])
        ->and($provider->client->calls[1][0])->toBe('createWebhook')
        ->and($provider->client->calls[1][1][1])->toBe(route('webhooks.source-control', ['github', $site]))
        ->and($provider->client->calls)->toHaveCount(2)
        ->and($site->quick_deploy)->toBeTrue()
        ->and($site->webhook_secret)->not->toBe('old-secret');
});

test('changing domain names the sites whose webhook could not be moved', function () {
    fakeSourceControl();
    Site::factory()->withRepository()->create(['domain' => 'shop.example.com', 'quick_deploy' => true, 'webhook_id' => 'hook-old']);

    $this->artisan('swage:domain-changed')
        ->expectsOutputToContain('1 webhook(s) could not be moved: shop.example.com.')
        ->assertFailed();
});
