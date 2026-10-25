<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\SaveDatabaseUser;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseUserResource;
use App\Jobs\DeleteDatabaseUser;
use App\Models\DatabaseUser;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DatabaseUserController extends Controller
{
    public function index(Server $server): AnonymousResourceCollection
    {
        return DatabaseUserResource::collection($server->databaseUsers()->with('databases')->orderBy('username')->get());
    }

    /**
     * Create a database user with access to the databases given.
     */
    public function store(Request $request, Server $server, SaveDatabaseUser $saveDatabaseUser): JsonResponse
    {
        Gate::authorize('update', $server);
        abort_unless($server->hasDatabase(), Response::HTTP_CONFLICT, __('This server runs no database engine.'));

        $user = $saveDatabaseUser->handle($server, null, [
            'engine' => $server->defaultDatabaseEngine()?->value,
            'databases' => [],
            ...$request->only(['engine', 'username', 'password', 'databases']),
        ]);

        return DatabaseUserResource::make($user->load('databases'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change the user's password or the databases it can reach. Its name and engine stay as they are.
     */
    public function update(Request $request, Server $server, DatabaseUser $databaseUser, SaveDatabaseUser $saveDatabaseUser): DatabaseUserResource
    {
        Gate::authorize('update', $server);

        $user = $saveDatabaseUser->handle($server, $databaseUser, [
            'databases' => $databaseUser->databases()->pluck('databases.id')->all(),
            ...$request->only(['password', 'databases']),
        ]);

        return DatabaseUserResource::make($user->load('databases'));
    }

    public function destroy(Server $server, DatabaseUser $databaseUser): JsonResponse
    {
        Gate::authorize('update', $server);

        $databaseUser->update(['status' => ResourceStatus::Removing]);
        DeleteDatabaseUser::dispatch($databaseUser);

        return response()->json(['message' => __('Deleting the database user.')], Response::HTTP_ACCEPTED);
    }
}
