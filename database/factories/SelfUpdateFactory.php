<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\SelfUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SelfUpdate>
 */
class SelfUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => DeploymentStatus::Finished,
            'from_commit' => fake()->sha1(),
            'to_commit' => fake()->sha1(),
            'output' => 'Updated.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
