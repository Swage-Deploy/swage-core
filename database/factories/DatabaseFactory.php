<?php

namespace Database\Factories;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
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
            'name' => fake()->unique()->lexify('db_??????'),
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
