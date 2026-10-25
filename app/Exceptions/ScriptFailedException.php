<?php

namespace App\Exceptions;

use App\Models\Task;
use RuntimeException;

class ScriptFailedException extends RuntimeException
{
    public function __construct(public readonly Task $task)
    {
        parent::__construct("Remote task [{$task->name}] failed with exit code {$task->exit_code}.");
    }
}
