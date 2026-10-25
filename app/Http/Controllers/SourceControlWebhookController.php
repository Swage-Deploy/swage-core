<?php

namespace App\Http\Controllers;

use App\Actions\Sites\QueueDeployment;
use App\Models\Site;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\SourceControlProviders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SourceControlWebhookController extends Controller
{
    /**
     * Deploy the site when its branch receives a push and quick deploy is enabled.
     */
    public function __invoke(Request $request, string $provider, Site $site, SourceControlProviders $providers, QueueDeployment $queueDeployment): Response
    {
        abort_unless($site->webhook_secret && $site->source_control === $provider, 404);

        $provider = $providers->find($provider) ?? abort(404);

        try {
            $push = $provider->push($request, $site->webhook_secret);
        } catch (InvalidWebhookException) {
            abort(403);
        }

        if ($push === null || ! $site->quick_deploy || $push->branch !== $site->branch) {
            return response()->noContent();
        }

        // The provider shows the delivery as failed, so a skipped push is visible in the repository's webhook settings.
        abort_unless($site->acceptsDeployments(), 409, 'The site is not ready to deploy.');

        $queueDeployment->handle($site, 'Push by '.($push->pusher ?? $provider->name()));

        return response()->noContent(202);
    }
}
