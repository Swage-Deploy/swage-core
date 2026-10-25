<?php

namespace App\Actions\Servers;

use App\Enums\TaskStatus;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\RemoteShell;
use App\Ssh\ScriptResult;
use Throwable;

class RunServerTask
{
    public function __construct(protected RemoteShell $shell) {}

    /**
     * Run a script on the server, recording it in the server's activity log.
     *
     * @throws ScriptFailedException
     */
    public function run(Server $server, string $name, string $script, ?string $as = null, int $timeout = 600): ScriptResult
    {
        $task = $server->tasks()->create([
            'name' => $name,
            'status' => TaskStatus::Running,
        ]);

        try {
            $result = $this->shell->run($server, $script, $as, $timeout);
        } catch (Throwable $exception) {
            $this->finish($task, new ScriptResult(exitCode: 255, output: $exception->getMessage()));

            throw $exception;
        }

        $this->finish($task, $result);

        if ($result->failed()) {
            throw new ScriptFailedException($task);
        }

        return $result;
    }

    protected function finish(Task $task, ScriptResult $result): void
    {
        $task->update([
            'status' => $result->successful() ? TaskStatus::Finished : TaskStatus::Failed,
            'exit_code' => $result->exitCode,
            'output' => $task->server->redactSecrets($result->timedOut ? $result->output."\n\nThe task timed out." : $result->output),
        ]);
    }
}
