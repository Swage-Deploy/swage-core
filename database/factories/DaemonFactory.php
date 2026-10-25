<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\Daemon;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Daemon>
 */
class DaemonFactory extends Factory
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
            'command' => 'php8.5 /home/swage/example.com/current/artisan reverb:start',
            'directory' => null,
            'user' => 'swage',
            'processes' => 1,
            'stop_wait_seconds' => 10,
            'status' => ResourceStatus::Installed,
        ];
    }
}
