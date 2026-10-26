<?php

use App\Actions\Users\Impersonation;
use App\Enums\ApiAbility;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

test('a new key is shown once, and giving write also gives read', function () {
    $user = User::factory()->create();

    $page = Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->call('add')
        ->set('name', 'CI deploys')
        ->set('expires', '30')
        ->set('access.deployments', 'write')
        ->set('access.sites', 'read')
        ->call('create')
        ->assertHasNoErrors();

    $key = $user->tokens()->sole();

    expect($key->name)->toBe('CI deploys')
        ->and($key->abilities)->toBe(['deployments:read', 'deployments:write', 'sites:read'])
        ->and($key->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($page->get('plainTextKey'))->toMatch('/^\d+\|swage_/');

    expect(PersonalAccessToken::findToken($page->get('plainTextKey'))->is($key))->toBeTrue();

    $page->call('forgetKey')->assertSet('plainTextKey', null);
});

test('a key needs access to at least one area', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->set('name', 'Nothing')
        ->call('create')
        ->assertHasErrors('access');

    expect($user->tokens()->count())->toBe(0);
});

test('full access gives every ability, and keys can be set to never expire', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->set('name', 'Everything')
        ->set('expires', 'never')
        ->call('fullAccess')
        ->call('create')
        ->assertHasNoErrors();

    $key = $user->tokens()->sole();

    expect($key->abilities)->toEqualCanonicalizing(ApiAbility::all())
        ->and($key->expires_at)->toBeNull();
});

test('keys are listed with what they can do, and can be revoked', function () {
    $user = User::factory()->create();
    $user->createToken('Reporting', ['servers:read', 'sites:read']);

    $page = Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->assertSee('Reporting')
        ->assertSee('Servers: read')
        ->assertSee('Never used');

    $page->call('revoke', $user->tokens()->sole()->id);

    expect($user->tokens()->count())->toBe(0);
});

test('nobody can revoke another user\'s key', function () {
    $owner = User::factory()->create();
    $key = $owner->createToken('Theirs', ['servers:read'])->accessToken;

    Livewire::actingAs(User::factory()->create())->test('pages::settings.api-keys')
        ->call('revoke', $key->id)
        ->assertNotFound();

    expect($key->fresh())->not->toBeNull();
});

test('an administrator signed in as someone cannot manage their keys', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($admin);
    app(Impersonation::class)->start($admin, $user);

    $this->get(route('api-keys.edit'))->assertRedirect(route('dashboard'));
});

test('removing a user removes their keys', function () {
    $user = User::factory()->create();
    $user->createToken('Gone', ['servers:read']);

    $user->delete();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('a key is given the root ability only when asked, and only with write access to daemons', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->set('name', 'Root only')
        ->set('access.servers', 'read')
        ->set('root', true)
        ->call('create')
        ->assertHasErrors(['root' => 'Running commands as root needs write access to daemons.']);

    expect($user->tokens()->count())->toBe(0);

    Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->set('name', 'Provisioner')
        ->call('fullAccess')
        ->set('root', true)
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('Full access')
        ->assertSee('Run commands as root');

    expect($user->tokens()->sole()->abilities)->toEqualCanonicalizing([...ApiAbility::all(), 'root']);
});
