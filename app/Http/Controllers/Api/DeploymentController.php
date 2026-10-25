<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\QueueDeployment;
use App\Actions\Sites\SetQuickDeploy;
use App\Deployments\DeployScript;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\SourceControl\SourceControlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeploymentController extends Controller
{
    /**
     * The site's deployments, newest first, without their output.
     */
    public function index(Server $server, Site $site): AnonymousResourceCollection
    {
        return DeploymentResource::collection($site->deployments()->latest('id')
            ->select(['id', 'site_id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author', 'started_at', 'finished_at', 'created_at'])
            ->paginate(50));
    }

    /**
     * Queue a deployment of the site.
     */
    public function store(Request $request, Server $server, Site $site, QueueDeployment $queueDeployment): JsonResponse
    {
        Gate::authorize('update', $site);

        abort_unless($site->hasRepository() && $site->status === ResourceStatus::Installed, Response::HTTP_CONFLICT, __('Install a repository before deploying.'));

        $deployment = $queueDeployment->handle($site, $request->user()->name);

        return DeploymentResource::make($deployment)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * A deployment with the full output of its deploy script.
     */
    public function show(Server $server, Site $site, Deployment $deployment): DeploymentResource
    {
        return DeploymentResource::make($deployment);
    }

    public function script(Server $server, Site $site): JsonResponse
    {
        return response()->json(['data' => ['deploy_script' => $site->deploy_script ?? DeployScript::default()]]);
    }

    public function updateScript(Request $request, Server $server, Site $site): JsonResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate(['deploy_script' => ['required', 'string', 'max:20000']]);

        $site->update(['deploy_script' => $validated['deploy_script']]);

        return $this->script($server, $site);
    }

    /**
     * Turn deploying on every push to the site's branch on or off. Swage creates the webhook through the server
     * owner's connected account, so only the owner can.
     */
    public function quickDeploy(Request $request, Server $server, Site $site, SetQuickDeploy $setQuickDeploy): JsonResponse
    {
        Gate::authorize('useSourceControl', $site);

        abort_unless($site->supportsQuickDeploy(), Response::HTTP_CONFLICT, __('Quick deploy needs a repository installed from a source control provider.'));

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        try {
            $setQuickDeploy->handle($site, (bool) $enabled);
        } catch (SourceControlException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => ['quick_deploy' => (bool) $site->refresh()->quick_deploy]]);
    }
}
