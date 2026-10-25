<?php

namespace App\Jobs;

use App\Models\Server;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes Swage's SSH key from a server it no longer manages. The job carries the key itself, because the server's
 * record is deleted before it runs, so its payload is encrypted.
 */
class RevokeServerAccess implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  array{name: string, ip_address: string, ssh_port: int, ssh_private_key: string, ssh_public_key: string, host_key: ?string}  $target
     */
    public function __construct(public array $target) {}

    /**
     * Capture what is needed to reach the server before its record is deleted.
     */
    public static function for(Server $server): self
    {
        return new self($server->only(['name', 'ip_address', 'ssh_port', 'ssh_private_key', 'ssh_public_key', 'host_key']));
    }

    /**
     * Execute the job. The server may already be gone, so a failure is only logged.
     */
    public function handle(RemoteShell $shell): void
    {
        $server = (new Server)->forceFill($this->target);

        try {
            $result = $shell->run($server, Script::render('revoke-access', [
                'publicKey' => explode(' ', $server->ssh_public_key)[1] ?? '',
            ]), timeout: 30);
        } catch (Throwable $exception) {
            Log::warning("Unable to remove Swage's SSH key from server [{$server->name}]: {$exception->getMessage()}");

            return;
        }

        if ($result->failed()) {
            Log::warning("Unable to remove Swage's SSH key from server [{$server->name}]: {$result->output}");
        }
    }
}
