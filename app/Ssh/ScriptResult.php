<?php

namespace App\Ssh;

final readonly class ScriptResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public bool $timedOut = false,
    ) {}

    /**
     * Determine if the script exited successfully.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /**
     * Determine if the script failed.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }
}
