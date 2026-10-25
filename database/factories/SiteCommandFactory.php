<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteCommand>
 */
class SiteCommandFactory extends Factory
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
            'user_id' => User::factory(),
            'command' => 'migrate --force',
            'status' => DeploymentStatus::Finished,
            'exit_code' => 0,
            'output' => 'Nothing to migrate.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
