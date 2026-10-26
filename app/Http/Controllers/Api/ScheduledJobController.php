<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\CreateScheduledJob;
use App\Enums\ApiAbility;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledJobResource;
use App\Jobs\RemoveScheduledJob;
use App\Models\ScheduledJob;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ScheduledJobController extends Controller
{
    /**
     * Every cron job on the server, including its sites' Laravel schedulers.
     */
    public function index(Server $server): AnonymousResourceCollection
    {
        return ScheduledJobResource::collection($server->scheduledJobs()->latest('id')->get());
    }

    public function store(Request $request, Server $server, CreateScheduledJob $createScheduledJob): JsonResponse
    {
        Gate::authorize('update', $server);

        // Only a key given the root ability can make something run as root, which controls the whole server.
        abort_if($request->input('user') === 'root' && ! $request->user()->tokenCan(ApiAbility::Root->value), Response::HTTP_FORBIDDEN, __('This API key cannot run commands as root. Create a key with "Run commands as root" for that.'));

        $job = $createScheduledJob->handle($server, [
            'user' => config('swage.user'),
            'frequency' => '* * * * *',
            ...$request->only(['command', 'user', 'frequency']),
        ]);

        return ScheduledJobResource::make($job)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Server $server, ScheduledJob $scheduledJob): JsonResponse
    {
        Gate::authorize('update', $server);

        $scheduledJob->update(['status' => ResourceStatus::Removing]);
        RemoveScheduledJob::dispatch($scheduledJob);

        return response()->json(['message' => __('Removing the scheduled job.')], Response::HTTP_ACCEPTED);
    }
}
