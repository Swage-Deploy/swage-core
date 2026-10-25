<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\RemoveServer;
use App\Enums\PhpVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Jobs\OptimizeServer;
use App\Jobs\RebootServer;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ServerController extends Controller
{
    /**
     * The servers the user can open: their own, those they are a member of, or every server for an administrator.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ServerResource::collection($request->user()->accessibleServers()->with('user')->orderBy('name')->get());
    }

    /**
     * Register a server. The response holds the command to run on it as root, which provisions it.
     */
    public function store(Request $request, CreateServer $createServer): JsonResponse
    {
        $versions = $request->input('php_versions', [PhpVersion::latest()->value]);

        $server = $createServer->fromInput($request->user(), [
            'type' => 'app',
            'ssh_port' => 22,
            'php_versions' => $versions,
            // The newest version installed is the command line's default unless another is chosen.
            'default_php_version' => is_array($versions) ? collect($versions)->filter(fn (mixed $version): bool => is_string($version))->sort('version_compare')->last() : null,
            'database' => 'mysql',
            'has_redis' => true,
            ...$request->only(['type', 'name', 'ip_address', 'private_ip_address', 'ssh_port', 'default_php_version', 'database', 'has_redis']),
        ]);

        return ServerResource::make($server->load('user'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Server $server): ServerResource
    {
        return ServerResource::make($server->load('user'));
    }

    /**
     * Stop managing the server. The machine and everything on it keep running.
     */
    public function destroy(Server $server, RemoveServer $removeServer): Response
    {
        Gate::authorize('delete', $server);

        $removeServer->handle($server);

        return response()->noContent();
    }

    public function reboot(Server $server): JsonResponse
    {
        Gate::authorize('update', $server);

        RebootServer::dispatch($server);

        return response()->json(['message' => __('Rebooting the server. Sites are unavailable for a minute or two while it starts again.')], Response::HTTP_ACCEPTED);
    }

    /**
     * Re-detect the hardware and rewrite every service config to match it.
     */
    public function optimize(Server $server): JsonResponse
    {
        Gate::authorize('update', $server);

        OptimizeServer::dispatch($server);

        return response()->json(['message' => __('Re-optimizing services. Follow along in the activity log.')], Response::HTTP_ACCEPTED);
    }
}
