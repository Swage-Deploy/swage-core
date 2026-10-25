<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['swage.admin.email' => 'admin@example.com']);
});

test('registration is disabled by default', function () {
    expect(config('swage.registration'))->toBeFalse();

    $this->get('/register')->assertNotFound();

    Livewire::test('pages::auth.register')->assertNotFound();

    expect(User::count())->toBe(0);
});

test('the login page does not offer sign up', function () {
    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
});

test('the seeder creates the administrator', function () {
    config(['swage.admin.password' => 'a-strong-password']);

    $this->seed(DatabaseSeeder::class);

    $admin = User::sole();

    expect($admin)
        ->email->toBe('admin@example.com')
        ->is_admin->toBeTrue()
        ->email_verified_at->not->toBeNull()
        ->and(Hash::check('a-strong-password', $admin->password))->toBeTrue();
});

test('the seeder generates a password when none is configured', function () {
    config(['swage.admin.password' => null]);

    $this->seed(DatabaseSeeder::class);

    expect(User::sole()->password)->not->toBeEmpty();
});

test('reseeding keeps the existing password and promotes the account', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'original-password']);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1)
        ->and($user->fresh()->is_admin)->toBeTrue()
        ->and(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

test('the seeder refuses to run without an administrator email', function () {
    config(['swage.admin.email' => null]);

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(RuntimeException::class, 'SWAGE_ADMIN_EMAIL');

    expect(User::count())->toBe(0);
});
