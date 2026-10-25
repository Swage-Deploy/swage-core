<?php

namespace App\Http\Controllers;

use App\Actions\Sites\QueueDeployment;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeployTriggerController extends Controller
{
    /**
     * Queue a deployment when the site's secret deploy URL is requested.
     *
     * The token may be sent as a bearer token instead of in the URL, which keeps it out of access logs.
     */
    public function __invoke(Request $request, Site $site, QueueDeployment $queueDeployment, ?string $token = null): JsonResponse
    {
        $token ??= (string) $request->bearerToken();

        abort_unless(filled($site->deploy_token) && hash_equals($site->deploy_token, $token), 404);
        abort_unless($site->acceptsDeployments(), 409, 'The site is not ready to deploy. Install its repository, or wait for its removal to finish.');

        $deployment = $queueDeployment->handle($site, 'Deploy URL');

        return response()->json(['deployment' => $deployment->id], 202);
    }
}
