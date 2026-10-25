<?php

use App\Actions\Users\Impersonation;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

/**
 * Sign in as an administrator who has two-factor authentication, then sign in as the user from the users page.
 */
function impersonate(User $user, ?User $admin = null): User
{
    $admin ??= User::factory()->admin()->create();
    $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($admin)->test('pages::users.index')->call('impersonate', $user->id)->assertRedirect(route('dashboard'));

    return $admin;
}

test('administrators sign in as a user and see swage as they do', function () {
    $server = Server::factory()->create(['name' => 'their-server']);
    $user = $server->user;

    $admin = impersonate($user);

    expect(auth()->id())->toBe($user->id)
        ->and(session(Impersonation::SESSION_KEY))->toBe($admin->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('their-server')
        ->assertSee('You are signed in as '.$user->name)
        ->assertSee($admin->email)
        ->assertSee(route('impersonation.stop'));
});

test('stopping signs the administrator back in as themselves', function () {
    $user = User::factory()->create();
    $admin = impersonate($user);

    $this->post(route('impersonation.stop'))->assertRedirect(route('users'));

    expect(auth()->id())->toBe($admin->id)
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});

test('an administrator who lost their rights meanwhile is signed out when stopping', function () {
    $user = User::factory()->create();
    $admin = impersonate($user);
    $admin->update(['is_admin' => false]);

    $this->post(route('impersonation.stop'))->assertRedirect('/');

    $this->assertGuest();
});

test('stopping needs an impersonation to stop', function () {
    $this->actingAs(User::factory()->admin()->create())->post(route('impersonation.stop'))->assertNotFound();
});

test('the impersonated user\'s two-factor authentication is not asked for', function () {
    config(['swage.require_two_factor' => true]);
    $user = User::factory()->create();

    impersonate($user);

    $this->get(route('dashboard'))->assertOk();
});

test('the user\'s account settings send the administrator back to the dashboard, which says why', function (string $route) {
    $user = User::factory()->create();
    impersonate($user);

    $this->withSession(['auth.password_confirmed_at' => time()])->get(route($route))->assertRedirect(route('dashboard'));

    $this->followingRedirects()->get(route($route))
        ->assertOk()
        ->assertSee('That page is part of their account settings');
})->with(['profile.edit', 'security.edit', 'source-control.edit', 'password.confirm']);

test('links to the user\'s account settings are hidden while impersonating', function () {
    $user = User::factory()->create();
    impersonate($user);

    $this->get(route('appearance.edit'))
        ->assertOk()
        ->assertDontSee(route('source-control.edit'))
        ->assertDontSee(route('profile.edit'))
        ->assertDontSee(route('security.edit'));
});

test('links to account settings show when not impersonating', function () {
    $this->actingAs(User::factory()->create())->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee(route('source-control.edit'))
        ->assertSee(route('profile.edit'));
});

test('the administrator pages are out of reach while impersonating', function () {
    impersonate(User::factory()->create());

    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('users'))->assertForbidden();
});

test('administrators, unverified users and yourself cannot be impersonated', function () {
    $admin = User::factory()->admin()->create();

    foreach ([User::factory()->admin()->create(), User::factory()->unverified()->create(), $admin] as $user) {
        Livewire::actingAs($admin)->test('pages::users.index')->call('impersonate', $user->id)->assertNoRedirect()->assertDispatched('toast-show');

        expect(auth()->id())->toBe($admin->id);
    }
});

test('people who are not administrators cannot impersonate', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect(fn () => app(Impersonation::class)->start($user, $other))->toThrow(InvalidArgumentException::class);
});
