<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servers\AddDatabase;
use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseResource;
use App\Jobs\DeleteDatabase;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DatabaseController extends Controller
{
    public function index(Server $server): AnonymousResourceCollection
    {
        return DatabaseResource::collection($server->databases()->orderBy('name')->get());
    }

    public function store(Request $request, Server $server, AddDatabase $addDatabase): JsonResponse
    {
        Gate::authorize('update', $server);
        abort_unless($server->hasDatabase(), Response::HTTP_CONFLICT, __('This server runs no database engine.'));

        $database = $addDatabase->handle($server, [
            'engine' => $server->defaultDatabaseEngine()?->value,
            ...$request->only(['engine', 'name']),
        ]);

        return DatabaseResource::make($database)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Drop the database and all of its data.
     */
    public function destroy(Server $server, Database $database): JsonResponse
    {
        Gate::authorize('update', $server);

        $database->update(['status' => ResourceStatus::Removing]);
        DeleteDatabase::dispatch($database);

        return response()->json(['message' => __('Dropping the database.')], Response::HTTP_ACCEPTED);
    }
}
