<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function userWithTwoFactor(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

test('visitors can register when registration is enabled', function () {
    config(['swage.registration' => true]);
    Event::fake([Registered::class]);

    $this->get(route('register'))->assertOk();

    Livewire::test('pages::auth.register')
        ->set('name', 'New User')
        ->set('email', 'new@example.com')
        ->set('password', 'a-long-password-123')
        ->set('password_confirmation', 'a-long-password-123')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::sole();

    expect($user->email)->toBe('new@example.com')
        ->and($user->is_admin)->toBeFalse()
        ->and(auth()->user()->is($user))->toBeTrue();

    Event::assertDispatched(Registered::class);
});

test('registration validates the account details', function () {
    config(['swage.registration' => true]);
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test('pages::auth.register')
        ->set('name', '')
        ->set('email', 'taken@example.com')
        ->set('password', 'a-long-password-123')
        ->set('password_confirmation', 'something-else')
        ->call('register')
        ->assertHasErrors(['name' => 'required', 'email' => 'unique', 'password' => 'confirmed']);

    expect(User::count())->toBe(1);
});

test('the login page links to registration only when it is enabled', function () {
    $this->get(route('login'))->assertDontSee('Sign up');

    config(['swage.registration' => true]);

    $this->get(route('login'))->assertSee('Sign up');
});

test('users without two-factor authentication are sent to set it up when it is required', function () {
    config(['swage.require_two_factor' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('users without two-factor authentication can still reach security settings and log out', function () {
    config(['swage.require_two_factor' => true]);

    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Two-factor authentication is required');

    $this->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});

test('users with two-factor authentication are let through when it is required', function () {
    config(['swage.require_two_factor' => true]);

    $this->actingAs(userWithTwoFactor())
        ->get(route('dashboard'))
        ->assertOk();
});

test('two-factor authentication can be made optional', function () {
    config(['swage.require_two_factor' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

test('two-factor authentication cannot be disabled when it is required', function () {
    config(['swage.require_two_factor' => true]);
    $user = userWithTwoFactor();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::settings.security')
        ->assertDontSee('Disable 2FA')
        ->call('disable')
        ->assertDispatched('toast-show');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
