<?php

namespace App\Actions\Servers;

use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Ssh\Script;
use InvalidArgumentException;

class SetSshAccess
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Let a site's user sign in over SSH, or stop it. Root can't be turned off, since Swage signs in as root.
     *
     * @throws ScriptFailedException when sshd rejects the change, which is then undone.
     */
    public function handle(Server $server, string $user, bool $enabled): void
    {
        if ($user === 'root' || ! in_array($user, $server->sshUsers(), true)) {
            throw new InvalidArgumentException("SSH access for {$user} cannot be changed.");
        }

        $this->runTask->run($server, ($enabled ? 'Allowing' : 'Stopping')." SSH sign-in for {$user}", Script::render('ssh.set-access', [
            'sshUser' => $user,
            'enabled' => $enabled,
        ]), timeout: 30);
    }
}
