<?php

namespace Database\Factories;

use App\Deployments\DeployScript;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Models\Site;
use App\SourceControl\SourceControlProviders;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
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
            'domain' => fake()->unique()->domainName(),
            'aliases' => [],
            'web_directory' => '/public',
            'php_version' => PhpVersion::Php85,
            'status' => ResourceStatus::Installed,
            'deploy_script' => DeployScript::default(),
            'deploy_token' => Str::random(48),
        ];
    }

    /**
     * Indicate that the site deploys in place instead of with zero downtime releases.
     */
    public function inPlace(): static
    {
        return $this->state(fn (array $attributes) => [
            'zero_downtime' => false,
        ]);
    }

    /**
     * Indicate that the site runs as its own system user.
     */
    public function isolated(?string $systemUser = null): static
    {
        return $this->state(fn (array $attributes) => [
            'system_user' => $systemUser ?? 'site_'.fake()->unique()->numberBetween(1, 99999),
        ]);
    }

    /**
     * Indicate that a GitHub repository has been installed on the site.
     */
    public function withRepository(): static
    {
        return $this->state(function (array $attributes): array {
            $repository = 'acme/'.fake()->slug(2);

            return [
                'source_control' => 'github',
                'repository' => $repository,
                'clone_url' => "git@github.com:{$repository}.git",
                'branch' => 'main',
                'deploy_key_private' => 'fake-deploy-key',
                'deploy_key_public' => 'ssh-ed25519 AAAAdeploy swage',
                'deploy_key_id' => (string) fake()->randomNumber(6),
            ];
        });
    }

    /**
     * Indicate that the site's repository was installed from a clone URL, with its deploy key added by hand.
     */
    public function withCustomRepository(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_control' => SourceControlProviders::CUSTOM,
            'repository' => 'git@git.example.com:acme/app.git',
            'clone_url' => 'git@git.example.com:acme/app.git',
            'branch' => 'main',
            'deploy_key_private' => 'fake-deploy-key',
            'deploy_key_public' => 'ssh-ed25519 AAAAdeploy swage',
            'deploy_key_id' => null,
        ]);
    }
}
