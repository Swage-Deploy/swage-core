<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\RunArtisanCommand;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteCommandResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Artisan commands run in a site's live release. They run with the site's access, so only those who can manage the
 * site can see or run them.
 */
class SiteCommandController extends Controller
{
    public function index(Server $server, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('update', $site);

        return SiteCommandResource::collection($site->commands()->with('user:id,name')->latest('id')
            ->select(['id', 'site_id', 'user_id', 'command', 'status', 'exit_code', 'started_at', 'finished_at', 'created_at'])
            ->paginate(50));
    }

    /**
     * Run a command, given as what follows "php artisan", in the background.
     */
    public function store(Request $request, Server $server, Site $site, RunArtisanCommand $runArtisanCommand): JsonResponse
    {
        Gate::authorize('update', $site);

        $command = $runArtisanCommand->handle($site, $request->user(), $request->input('command'));

        return SiteCommandResource::make($command->load('user:id,name'))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Server $server, Site $site, SiteCommand $command): SiteCommandResource
    {
        Gate::authorize('update', $site);

        return SiteCommandResource::make($command->load('user:id,name'));
    }
}
