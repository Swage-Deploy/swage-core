<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\ScheduledJob;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledJob>
 */
class ScheduledJobFactory extends Factory
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
            'command' => 'php8.5 /home/swage/example.com/current/artisan backup:run',
            'user' => 'swage',
            'frequency' => '0 0 * * *',
            'status' => ResourceStatus::Installed,
        ];
    }
}
