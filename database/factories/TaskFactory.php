<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'name' => 'Installing site',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'output' => 'Done.',
        ];
    }
}
