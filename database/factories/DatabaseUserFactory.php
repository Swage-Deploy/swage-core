<?php

namespace Database\Factories;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Models\DatabaseUser;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DatabaseUser>
 */
class DatabaseUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'engine' => DatabaseEngine::Mysql,
            'username' => fake()->unique()->lexify('user_??????'),
            'password' => Str::password(24, symbols: false),
            'status' => ResourceStatus::Installed,
        ];
    }

    /**
     * Indicate that it belongs to the server's PostgreSQL.
     */
    public function postgres(): static
    {
        return $this->state(fn (array $attributes) => [
            'engine' => DatabaseEngine::Postgres,
        ]);
    }
}
