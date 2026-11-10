<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\ChangePhpVersions;
use App\Actions\Servers\CreateServer;
use App\Actions\Servers\RemoveServer;
use App\Enums\PhpVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Jobs\OptimizeServer;
use App\Jobs\RebootServer;
use App\Models\Server;
use App\Models\ServerMetric;
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
        return ServerResource::collection($request->user()->accessibleServers()->with(['user', 'latestMetric'])->orderBy('name')->get());
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
        return ServerResource::make($server->load(['user', 'latestMetric']));
    }

    /**
     * The latest reading of the server's CPU, load, memory, swap and disk, and hourly averages and peaks over the
     * past `hours`, up to a week.
     */
    public function metrics(Request $request, Server $server): JsonResponse
    {
        $request->validate(['hours' => ['nullable', 'integer', 'min:1', 'max:'.(ServerMetric::RETENTION_DAYS * 24)]]);

        $readings = $server->metrics()->where('created_at', '>=', now()->subHours($request->integer('hours', 24)))->get();

        return response()->json(['data' => [
            'latest' => $server->latestMetric?->usage(),
            'hourly' => ServerMetric::hourly($readings),
        ]]);
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
     * Install a PHP version beside the server's others, for its sites to switch to.
     */
    public function installPhp(Request $request, Server $server, ChangePhpVersions $changePhpVersions): JsonResponse
    {
        Gate::authorize('update', $server);

        return $this->phpChangeResponse($changePhpVersions->install($server, $request->input('version')), __('Installing PHP :version. Follow along in the activity log.', ['version' => $request->input('version')]));
    }

    /**
     * Make an installed PHP version the one `php` runs on the command line.
     */
    public function makePhpDefault(Server $server, string $version, ChangePhpVersions $changePhpVersions): JsonResponse
    {
        Gate::authorize('update', $server);

        return $this->phpChangeResponse($changePhpVersions->makeDefault($server, $version), __('Making PHP :version the default. Follow along in the activity log.', ['version' => $version]));
    }

    /**
     * Remove a PHP version nothing on the server runs any more.
     */
    public function removePhp(Server $server, string $version, ChangePhpVersions $changePhpVersions): JsonResponse
    {
        Gate::authorize('update', $server);

        return $this->phpChangeResponse($changePhpVersions->remove($server, $version), __('Removing PHP :version. Follow along in the activity log.', ['version' => $version]));
    }

    protected function phpChangeResponse(bool $started, string $message): JsonResponse
    {
        return $started
            ? response()->json(['message' => $message], Response::HTTP_ACCEPTED)
            : response()->json(['message' => __('Another install is already running on this server. Try again once it finishes.')], Response::HTTP_CONFLICT);
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
