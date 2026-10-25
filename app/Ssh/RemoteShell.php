<?php

namespace App\Ssh;

use App\Models\Server;
use Closure;

interface RemoteShell
{
    /**
     * Run a bash script on the server as root, or as the given user.
     *
     * @param  (Closure(string): void)|null  $onOutput
     */
    public function run(Server $server, string $script, ?string $as = null, int $timeout = 600, ?Closure $onOutput = null): ScriptResult;

    /**
     * Run a bash script on the server as root, passing what it writes to standard output to the callback as it arrives.
     * Standard error is kept apart from it, as the result's output, so a binary stream such as a database dump stays intact.
     *
     * @param  Closure(string): void  $onOutput
     */
    public function stream(Server $server, string $script, Closure $onOutput, int $timeout = 600): ScriptResult;

    /**
     * Copy a local file to the given path on the server, as root.
     */
    public function upload(Server $server, string $localPath, string $remotePath): void;
}
