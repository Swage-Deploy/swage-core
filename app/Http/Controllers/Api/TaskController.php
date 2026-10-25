<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /**
     * Every script Swage has run on the server, newest first, without their output.
     */
    public function index(Server $server): AnonymousResourceCollection
    {
        return TaskResource::collection($server->tasks()->latest('id')
            ->select(['id', 'server_id', 'name', 'status', 'exit_code', 'created_at', 'updated_at'])
            ->paginate(50));
    }

    public function show(Server $server, Task $task): TaskResource
    {
        return TaskResource::make($task);
    }
}
