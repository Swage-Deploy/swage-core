<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use App\SourceControl\SourceControlProviders;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes the deploy keys and webhooks Swage added to repositories for sites it no longer manages. The job carries
 * what it removes and the credentials to remove it with, because the sites and even their owner may be deleted before
 * it runs, so its payload is encrypted.
 */
class RevokeSourceControlAccess implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  list<array{provider: string, connection: array<string, mixed>, repository: string, deploy_key_id: ?string, webhook_id: ?string}>  $repositories
     */
    public function __construct(public array $repositories) {}

    /**
     * Capture the deploy keys and webhooks of sites before they are deleted, with the owner's credentials for each
     * site's provider. Custom repositories and providers the owner is no longer connected to are skipped.
     *
     * @param  iterable<Site>  $sites
     */
    public static function forSites(User $user, iterable $sites): self
    {
        $connections = $user->sourceControlConnections()->get()->keyBy('provider');

        $repositories = collect($sites)
            ->filter(fn (Site $site): bool => $site->hasRepository()
                && ! $site->usesCustomRepository()
                && ($site->deploy_key_id || $site->webhook_id)
                && $connections->has($site->source_control))
            ->map(fn (Site $site): array => [
                'provider' => $site->source_control,
                'connection' => [
                    'id' => $connections[$site->source_control]->id,
                    ...$connections[$site->source_control]->only(['provider', 'account_id', 'username', 'token', 'refresh_token']),
                    'expires_at' => $connections[$site->source_control]->expires_at?->toIso8601String(),
                ],
                'repository' => $site->repository,
                'deploy_key_id' => $site->deploy_key_id,
                'webhook_id' => $site->webhook_id,
            ])
            ->values()
            ->all();

        return new self($repositories);
    }

    /**
     * Execute the job. Keys and webhooks already removed on the provider are skipped, and a provider whose plugin
     * was removed is logged and skipped.
     */
    public function handle(SourceControlProviders $providers): void
    {
        foreach ($this->repositories as $repository) {
            $provider = $providers->find($repository['provider']);

            if ($provider === null) {
                Log::warning("Unable to remove Swage's deploy key and webhook from {$repository['repository']}: the {$repository['provider']} provider is not installed.");

                continue;
            }

            try {
                $client = $provider->client($this->connection($repository['connection']));

                if ($repository['deploy_key_id']) {
                    $client->deleteDeployKey($repository['repository'], $repository['deploy_key_id']);
                }

                if ($repository['webhook_id']) {
                    $client->deleteWebhook($repository['repository'], $repository['webhook_id']);
                }
            } catch (Throwable $exception) {
                Log::warning("Unable to remove Swage's deploy key and webhook from {$repository['repository']}: {$exception->getMessage()}");
            }
        }
    }

    /**
     * The owner's connection as it is now, or as it was when the job was queued if it has since been deleted. A
     * deleted connection is never saved again, even when its provider refreshes the token.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function connection(array $attributes): SourceControlConnection
    {
        return SourceControlConnection::find($attributes['id'])
            ?? (new SourceControlConnection)->forceFill(collect($attributes)->except('id')->all());
    }
}
