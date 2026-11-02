<?php

namespace App\Actions\Servers;

use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Servers\AuthorizedKey;
use App\Ssh\Script;
use InvalidArgumentException;

class SetSwageOnlySsh
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Turn SSH off for everyone but Swage, which keeps signing in as root with its own key, or turn it back on. Users'
     * authorized_keys files are left alone, so their keys work again once SSH is back on.
     *
     * @throws ScriptFailedException when sshd rejects the change, which is then undone.
     */
    public function handle(Server $server, bool $swageOnly): void
    {
        $panelKey = AuthorizedKey::parse((string) $server->ssh_public_key);

        if ($swageOnly && $panelKey === null) {
            throw new InvalidArgumentException('Swage\'s key for this server could not be read.');
        }

        $this->runTask->run($server, $swageOnly ? 'Turning off SSH for everyone but Swage' : 'Turning SSH back on', Script::render('ssh.swage-only', [
            'swageOnly' => $swageOnly,
            'panelKey' => $panelKey,
            'siteUsers' => $server->sites()->pluck('system_user')->all(),
        ]), timeout: 30);
    }
}
