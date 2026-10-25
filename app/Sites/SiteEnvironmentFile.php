<?php

namespace App\Sites;

use App\Models\Site;
use App\Ssh\RemoteShell;
use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

/**
 * Reads a site's .env from its server without recording it in the server's activity log.
 */
class SiteEnvironmentFile
{
    public function __construct(protected RemoteShell $shell) {}

    /**
     * Get the site's environment values, or null when the site has no .env.
     *
     * @return array<string, string|null>|null
     *
     * @throws RuntimeException
     */
    public function read(Site $site): ?array
    {
        $path = escapeshellarg($site->path().'/shared/.env');

        $result = $this->shell->run($site->server, "if [ -f {$path} ]; then echo SWAGE_ENV_FOUND; cat {$path}; fi", timeout: 30);

        if ($result->failed()) {
            throw new RuntimeException("Unable to read the environment of {$site->domain}.");
        }

        if (! str_starts_with($result->output, 'SWAGE_ENV_FOUND')) {
            return null;
        }

        try {
            return Dotenv::parse(substr($result->output, strlen("SWAGE_ENV_FOUND\n")));
        } catch (Throwable $exception) {
            throw new RuntimeException("The environment of {$site->domain} could not be parsed: {$exception->getMessage()}", previous: $exception);
        }
    }
}
