<?php

namespace App\Ssh;

use App\Exceptions\HostKeyMismatchException;
use App\Models\Server;
use Closure;
use Illuminate\Support\Str;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SSH2;
use RuntimeException;

class PhpseclibRemoteShell implements RemoteShell
{
    /**
     * Run a bash script on the server as root, or as the given user.
     *
     * @param  (Closure(string): void)|null  $onOutput
     */
    public function run(Server $server, string $script, ?string $as = null, int $timeout = 600, ?Closure $onOutput = null): ScriptResult
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout);
        $ssh->enableQuietMode();

        $output = '';

        $ssh->exec($this->wrap($script, $as), function (string $chunk) use (&$output, $onOutput): void {
            $output .= $chunk;

            if ($onOutput) {
                $onOutput($chunk);
            }
        });

        $result = new ScriptResult(
            exitCode: $ssh->getExitStatus() ?? 1,
            output: $output.$ssh->getStdError(),
            timedOut: $ssh->isTimeout(),
        );

        $ssh->disconnect();

        return $result;
    }

    /**
     * Run a bash script on the server as root, passing what it writes to standard output to the callback as it arrives.
     * Standard error is kept apart from it, as the result's output, so a binary stream such as a database dump stays intact.
     *
     * @param  Closure(string): void  $onOutput
     */
    public function stream(Server $server, string $script, Closure $onOutput, int $timeout = 600): ScriptResult
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout);
        $ssh->enableQuietMode();

        $ssh->exec($this->wrap($script, null, mergeErrors: false), $onOutput);

        $result = new ScriptResult(
            exitCode: $ssh->getExitStatus() ?? 1,
            output: $ssh->getStdError(),
            timedOut: $ssh->isTimeout(),
        );

        $ssh->disconnect();

        return $result;
    }

    /**
     * Copy a local file to the given path on the server, as root.
     */
    public function upload(Server $server, string $localPath, string $remotePath): void
    {
        $sftp = $this->connect($server, SFTP::class);

        try {
            $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
        } finally {
            $sftp->disconnect();
        }
    }

    /**
     * Open an SSH, or SFTP, connection to the server as root.
     *
     * @template T of SSH2
     *
     * @param  class-string<T>  $client
     * @return T
     */
    protected function connect(Server $server, string $client = SSH2::class): SSH2
    {
        $ssh = new $client($server->ip_address, $server->ssh_port, 15);
        $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519']]);

        $this->verifyHostKey($server, $ssh);

        if (! $ssh->login('root', PublicKeyLoader::loadPrivateKey($server->ssh_private_key))) {
            throw new RuntimeException("Unable to authenticate with server [{$server->name}] over SSH.");
        }

        return $ssh;
    }

    /**
     * Refuse to send anything to a host whose key differs from the one Swage pinned for the server.
     *
     * The key is pinned when the server reports it during provisioning, so a server without one is never trusted.
     *
     * @throws HostKeyMismatchException
     */
    protected function verifyHostKey(Server $server, SSH2 $ssh): void
    {
        $hostKey = $ssh->getServerPublicHostKey();

        if ($hostKey === null || blank($server->host_key)) {
            throw new RuntimeException("Unable to verify the SSH host key of server [{$server->name}].");
        }

        if (! hash_equals($server->host_key, $hostKey)) {
            throw new HostKeyMismatchException($server);
        }
    }

    /**
     * Wrap the script in a heredoc so it can be piped to bash verbatim.
     */
    protected function wrap(string $script, ?string $as, bool $mergeErrors = true): string
    {
        $delimiter = 'SWAGE_'.Str::upper(Str::random(16));

        $shell = $as ? 'sudo -H -u '.escapeshellarg($as).' bash -s' : 'bash -s';
        $redirect = $mergeErrors ? ' 2>&1' : '';

        return "{$shell}{$redirect} <<'{$delimiter}'\n{$script}\n{$delimiter}";
    }
}
