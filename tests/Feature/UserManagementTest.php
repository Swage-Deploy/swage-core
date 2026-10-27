<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function usersPage(?User $admin = null)
{
    return Livewire::actingAs($admin ?? User::factory()->admin()->create())->test('pages::users.index');
}

test('the users page is for administrators who have just confirmed their password', function () {
    $this->actingAs(User::factory()->create())->get(route('users'))->assertForbidden();
    $this->actingAs($admin = User::factory()->admin()->create())->get(route('users'))->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('users'))
        ->assertOk()
        ->assertSee($admin->email);
});

test('administrators add users with a generated password shown once', function () {
    $page = usersPage()
        ->call('add')
        ->set('name', 'Taylor')
        ->set('email', 'taylor@example.com')
        ->set('is_admin', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('generatedFor', 'taylor@example.com');

    $user = User::firstWhere('email', 'taylor@example.com');
    $password = $page->get('generatedPassword');

    expect($user)
        ->name->toBe('Taylor')
        ->is_admin->toBeTrue()
        ->email_verified_at->not->toBeNull()
        ->and(strlen($password))->toBe(20)
        ->and(Hash::check($password, $user->password))->toBeTrue();

    $page->call('forgetPassword')->assertSet('generatedPassword', null);
});

test('new users need a name and an unused email address', function () {
    $existing = User::factory()->create();

    usersPage()->call('add')->set('name', '')->set('email', $existing->email)->call('save')->assertHasErrors(['name', 'email']);
});

test('administrators reset a user\'s password, which revokes their api keys', function () {
    $user = User::factory()->create(['remember_token' => 'remembered']);
    $user->createToken('ci', ['servers:read']);

    $password = usersPage()->call('resetPassword', $user->id)->assertSet('generatedFor', $user->email)->get('generatedPassword');

    $user->refresh();

    expect(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('remembered')
        ->and($user->tokens()->count())->toBe(0);
});

test('a changed password signs out every other session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['password' => 'a-new-password-1234'])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('administrators reset a user\'s two-factor authentication', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    usersPage()->call('resetTwoFactor', $user->id);

    expect($user->fresh())
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
});

test('administrators remove users who own no servers', function () {
    $user = User::factory()->create();

    usersPage()->call('remove', $user->id);

    expect(User::find($user->id))->toBeNull();
});

test('users who still own servers are not removed', function () {
    $server = Server::factory()->create();

    usersPage()->call('remove', $server->user_id);

    expect(User::find($server->user_id))->not->toBeNull()
        ->and($server->fresh())->not->toBeNull();
});

test('administrators cannot change their own account from the users page', function (string $action) {
    $admin = User::factory()->admin()->create();

    usersPage($admin)->call($action, $admin->id)->assertDispatched('toast-show')->assertSet('generatedPassword', null)->assertSet('editingId', null);

    expect(User::find($admin->id))->not->toBeNull();
})->with(['edit', 'resetPassword', 'resetTwoFactor', 'remove']);

test('administrators change a user\'s name, email address and whether they are an administrator', function () {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com', 'is_admin' => false]);

    usersPage()
        ->call('edit', $user->id)
        ->assertSet('name', 'Old')
        ->assertSet('editingId', $user->id)
        ->set('name', 'New')
        ->set('email', 'new@example.com')
        ->set('is_admin', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($user->fresh())->name->toBe('New')->email->toBe('new@example.com')->is_admin->toBeTrue();

    usersPage()->call('edit', $user->id)->set('is_admin', false)->call('save');

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('a user keeps their own email address but cannot take another user\'s', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);
    $other = User::factory()->create();

    usersPage()->call('edit', $user->id)->call('save')->assertHasNoErrors();
    usersPage()->call('edit', $user->id)->set('email', $other->email)->call('save')->assertHasErrors(['email']);

    expect($user->fresh()->email)->toBe('mine@example.com');
});

test('people who are not administrators cannot manage users', function () {
    $user = User::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::users.index')->assertForbidden();

    expect(User::find($user->id))->not->toBeNull();
});
