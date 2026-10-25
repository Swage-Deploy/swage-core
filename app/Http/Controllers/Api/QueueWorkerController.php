<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\CreateQueueWorker;
use App\Actions\Sites\SetScheduler;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DaemonResource;
use App\Http\Resources\ScheduledJobResource;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Daemon;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class QueueWorkerController extends Controller
{
    public function index(Server $server, Site $site): AnonymousResourceCollection
    {
        return DaemonResource::collection($site->daemons()->latest('id')->get());
    }

    public function store(Request $request, Server $server, Site $site, CreateQueueWorker $createQueueWorker): JsonResponse
    {
        Gate::authorize('update', $site);

        $daemon = $createQueueWorker->handle($site, [
            'connection' => $site->server->has_redis ? 'redis' : 'database',
            'queue' => 'default',
            'processes' => 1,
            'timeout' => 60,
            'sleep' => 3,
            'tries' => 3,
            ...$request->only(['connection', 'queue', 'processes', 'timeout', 'sleep', 'tries']),
        ]);

        return DaemonResource::make($daemon)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function restart(Server $server, Site $site, Daemon $daemon): JsonResponse
    {
        Gate::authorize('update', $site);

        InstallDaemon::dispatch($daemon);

        return response()->json(['message' => __('Restarting the worker.')], Response::HTTP_ACCEPTED);
    }

    public function destroy(Server $server, Site $site, Daemon $daemon): JsonResponse
    {
        Gate::authorize('update', $site);

        $daemon->update(['status' => ResourceStatus::Removing]);
        RemoveDaemon::dispatch($daemon);

        return response()->json(['message' => __('Removing the worker.')], Response::HTTP_ACCEPTED);
    }

    public function scheduler(Server $server, Site $site): JsonResponse
    {
        $scheduler = SetScheduler::current($site);

        return response()->json(['data' => ['enabled' => $scheduler !== null, 'scheduled_job' => $scheduler ? ScheduledJobResource::make($scheduler) : null]]);
    }

    /**
     * Turn the site's Laravel scheduler on or off.
     */
    public function updateScheduler(Request $request, Server $server, Site $site, SetScheduler $setScheduler): JsonResponse
    {
        Gate::authorize('update', $site);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $setScheduler->handle($site, (bool) $enabled);

        return $this->scheduler($server, $site);
    }
}
