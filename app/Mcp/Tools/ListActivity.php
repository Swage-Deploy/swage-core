<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class ListActivity extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::ServersRead;

    protected string $description = 'List a server\'s activity log, newest first: provisioning, installs, certificates, config changes and other tasks Swage ran on it. Use get-task for a task\'s output.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->integer()->description('The server\'s ID, from list-servers.')->required(),
            'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->description('Only list tasks with this status.'),
            'limit' => $schema->integer()->description('How many to list, up to 50. Defaults to 20.')->min(1)->max(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $server = $this->findServer($request, $request->get('server_id'));

        if (! $server) {
            return $this->notFound('server', $request->get('server_id'));
        }

        $tasks = $server->tasks()
            ->when(TaskStatus::tryFrom((string) $request->get('status')), fn ($query, TaskStatus $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(max(1, min(50, (int) $request->get('limit', 20))))
            ->get(['id', 'name', 'status', 'exit_code', 'created_at', 'updated_at']);

        return Response::json($tasks->map(fn (Task $task): array => [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'started_at' => self::time($task->created_at),
            'updated_at' => self::time($task->updated_at),
        ])->all());
    }
}
