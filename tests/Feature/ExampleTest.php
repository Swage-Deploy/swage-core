<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_offered_the_login_page(): void
    {
        config(['swage.registration' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('login'))
            ->assertDontSee(route('register'))
            ->assertDontSee('Laravel');
    }

    public function test_guests_are_offered_registration_when_it_is_enabled(): void
    {
        config(['swage.registration' => true]);

        $this->get(route('home'))->assertOk()->assertSee(route('register'));
    }

    public function test_signed_in_users_are_offered_their_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('dashboard'))
            ->assertDontSee(route('login'));
    }
}
