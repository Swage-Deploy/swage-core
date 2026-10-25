<?php

namespace Database\Factories;

use App\Models\SourceControlConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SourceControlConnection>
 */
class SourceControlConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'github',
            'account_id' => (string) fake()->unique()->randomNumber(8),
            'username' => fake()->userName(),
            'token' => 'token-'.Str::random(20),
        ];
    }
}
