<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Servers\SshAccess;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Support\Str;
use RuntimeException;

class ReadSshAccess
{
    public function __construct(protected RemoteShell $shell) {}

    /**
     * Read each user's authorized keys and which users sshd refuses from the server. Site users' files are read as
     * those users, so a link one puts in place of its file cannot make root read another file.
     *
     * @throws RuntimeException when the server cannot be reached.
     */
    public function handle(Server $server): SshAccess
    {
        // A random marker starts each section, so a site user cannot fake another user's keys from its own file.
        $marker = '@@'.Str::random(32);

        $result = $this->shell->run($server, Script::render('ssh.read-access', [
            'marker' => $marker,
            'sshUsers' => $server->sshUsers(),
        ]), timeout: 30);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->output));
        }

        return SshAccess::fromOutput($result->output, $marker);
    }
}
