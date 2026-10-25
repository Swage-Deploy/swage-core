<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\Certificate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
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
            'domains' => fn (array $attributes) => [Site::find($attributes['site_id'])?->domain ?? fake()->domainName()],
            'status' => ResourceStatus::Installed,
            'expires_at' => now()->addDays(90),
        ];
    }
}
