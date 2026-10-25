<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Ssh\RemoteShell;
use RuntimeException;

class ReadSiteEnvironment
{
    public function __construct(protected RemoteShell $shell) {}

    /**
     * Read the site's shared .env file from the server.
     *
     * @throws RuntimeException when the server cannot be reached or the file cannot be read.
     */
    public function handle(Site $site): string
    {
        $result = $this->shell->run($site->server, 'cat '.escapeshellarg($site->path().'/shared/.env'), timeout: 20);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->output));
        }

        return $result->output;
    }
}
