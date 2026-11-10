<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetTask extends SwageTool
{
    protected ?ApiAbility $ability = ApiAbility::ServersRead;

    protected string $description = 'Get a task from a server\'s activity log with its output. Long output is cut to its end, where errors usually are.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task\'s ID, from list-activity or get-server.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $task = is_numeric($id = $request->get('task_id')) ? Task::find((int) $id) : null;
        $server = $task ? $this->findServer($request, $task->server_id) : null;

        if (! $server) {
            return $this->notFound('task', $id);
        }

        return Response::json([
            'id' => $task->id,
            'server' => ['id' => $server->id, 'name' => $server->name],
            'name' => $task->name,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'started_at' => self::time($task->created_at),
            'updated_at' => self::time($task->updated_at),
            'output' => self::tail($task->output),
        ]);
    }
}
