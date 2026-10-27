<?php

namespace Database\Factories;

use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
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
            'name' => fake()->unique()->slug(2),
            'ip_address' => fake()->ipv4(),
            'ssh_port' => 22,
            'status' => ServerStatus::Active,
            'provision_token' => Str::random(64),
            'cpu_cores' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 80,
            'os_version' => '24.04',
            'php_versions' => [PhpVersion::Php85],
            'default_php_version' => PhpVersion::Php85,
            'has_mysql' => true,
            'has_redis' => true,
            'ssh_private_key' => 'fake-private-key',
            'ssh_public_key' => 'ssh-ed25519 AAAAfake swage',
            'database_password' => Str::password(32, symbols: false),
            'redis_password' => Str::password(40, symbols: false),
            'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKeyForTests',
            'provisioned_at' => now(),
        ];
    }

    /**
     * Indicate that the server only runs databases, without nginx, PHP or Redis.
     */
    public function database(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServerType::Database,
            'php_versions' => [],
            'default_php_version' => null,
            'has_redis' => false,
            'redis_password' => null,
        ]);
    }

    /**
     * Indicate that the server runs PostgreSQL instead of MySQL.
     */
    public function postgres(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_mysql' => false,
            'has_postgres' => true,
        ]);
    }

    /**
     * Indicate that the server runs PostgreSQL with the pgvector extension.
     */
    public function pgvector(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_mysql' => false,
            'has_postgres' => true,
            'has_pgvector' => true,
        ]);
    }

    /**
     * Indicate that the server is waiting for its provisioning command to be run.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Pending,
            'cpu_cores' => null,
            'memory_mb' => null,
            'disk_gb' => null,
            'os_version' => null,
            'provisioned_at' => null,
        ]);
    }

    /**
     * Indicate that the server is running its provisioning script.
     */
    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Provisioning,
            'provisioning_step' => 'Installing nginx',
            'provisioned_at' => null,
        ]);
    }
}
