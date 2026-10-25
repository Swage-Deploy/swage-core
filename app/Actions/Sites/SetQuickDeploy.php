<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\SourceControl\SourceControlProviders;
use Illuminate\Support\Str;
use LogicException;

class SetQuickDeploy
{
    public function __construct(protected SourceControlProviders $providers) {}

    /**
     * Enable or disable deploying the site whenever its branch is pushed to, through a webhook on its provider.
     */
    public function handle(Site $site, bool $enabled): void
    {
        if (! $site->supportsQuickDeploy()) {
            throw new LogicException("{$site->domain} has no provider to create a webhook with.");
        }

        $client = $this->providers->clientFor($site->server->user, $site->source_control);

        if ($enabled && ! $site->webhook_id) {
            $secret = Str::random(40);

            $site->update([
                'webhook_secret' => $secret,
                'webhook_id' => $client->createWebhook($site->repository, route('webhooks.source-control', [$site->source_control, $site]), $secret),
            ]);
        }

        if (! $enabled && $site->webhook_id) {
            $client->deleteWebhook($site->repository, $site->webhook_id);

            $site->update(['webhook_secret' => null, 'webhook_id' => null]);
        }

        $site->update(['quick_deploy' => $enabled]);
    }
}
