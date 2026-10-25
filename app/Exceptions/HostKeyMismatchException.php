<?php

namespace App\Exceptions;

use App\Models\Server;
use RuntimeException;

class HostKeyMismatchException extends RuntimeException
{
    public function __construct(public readonly Server $server)
    {
        parent::__construct("The SSH host key of server [{$server->name}] has changed, so Swage did not connect. If you rebuilt or reinstalled the server, remove it from Swage and connect it again. Otherwise someone may be intercepting the connection.");
    }
}
