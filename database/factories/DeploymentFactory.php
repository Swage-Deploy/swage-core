<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'status' => DeploymentStatus::Finished,
            'triggered_by' => fake()->name(),
            'commit_hash' => fake()->sha1(),
            'commit_message' => fake()->sentence(),
            'commit_author' => fake()->name(),
            'output' => 'Deployed.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
