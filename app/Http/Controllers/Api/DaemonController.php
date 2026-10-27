<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\CreateDaemon;
use App\Enums\ApiAbility;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DaemonResource;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Daemon;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DaemonController extends Controller
{
    /**
     * Every daemon on the server, including its sites' queue workers.
     */
    public function index(Server $server): AnonymousResourceCollection
    {
        return DaemonResource::collection($server->daemons()->latest('id')->get());
    }

    public function store(Request $request, Server $server, CreateDaemon $createDaemon): JsonResponse
    {
        Gate::authorize('update', $server);

        // Only the server's owner, with a key given the root ability, can make something run as root, which controls the
        // whole server.
        if ($request->input('user') === 'root') {
            Gate::authorize('runAsRoot', $server);
            abort_unless($request->user()->tokenCan(ApiAbility::Root->value), Response::HTTP_FORBIDDEN, __('This API key cannot run commands as root. Create a key with "Run commands as root" for that.'));
        }

        $daemon = $createDaemon->handle($server, [
            'user' => config('swage.user'),
            'processes' => 1,
            'stop_wait_seconds' => 10,
            ...$request->only(['command', 'directory', 'user', 'processes', 'stop_wait_seconds']),
        ]);

        return DaemonResource::make($daemon)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function restart(Server $server, Daemon $daemon): JsonResponse
    {
        Gate::authorize('update', $server);

        InstallDaemon::dispatch($daemon);

        return response()->json(['message' => __('Restarting the daemon.')], Response::HTTP_ACCEPTED);
    }

    public function destroy(Server $server, Daemon $daemon): JsonResponse
    {
        Gate::authorize('update', $server);

        $daemon->update(['status' => ResourceStatus::Removing]);
        RemoveDaemon::dispatch($daemon);

        return response()->json(['message' => __('Removing the daemon.')], Response::HTTP_ACCEPTED);
    }
}
