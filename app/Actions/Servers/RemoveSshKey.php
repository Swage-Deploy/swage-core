<?php

namespace App\Actions\Servers;

use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Servers\AuthorizedKey;
use App\Ssh\Script;
use InvalidArgumentException;

class RemoveSshKey
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Remove a key from a user's authorized_keys on the server. Swage's own key is never removed, since Swage would
     * lose its way into the server.
     *
     * @throws ScriptFailedException
     */
    public function handle(Server $server, string $user, AuthorizedKey $key): void
    {
        if (! in_array($user, $server->sshUsers(), true) || $key->is($server->ssh_public_key)) {
            throw new InvalidArgumentException('This key cannot be removed.');
        }

        $this->runTask->run($server, "Removing SSH key {$key->fingerprint()} for {$user}", Script::render('ssh.remove-key', [
            'sshUser' => $user,
            'key' => $key,
        ]), timeout: 30);
    }
}
