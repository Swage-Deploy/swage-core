<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WriteSiteEnvironment
{
    public function __construct(protected RemoteShell $shell) {}

    /**
     * Write the site's shared .env file, optionally clear the config cache, and reload PHP.
     *
     * @throws ValidationException
     * @throws RuntimeException when the file could not be written, with the server's output.
     */
    public function handle(Site $site, ?string $environment, bool $clearConfig): void
    {
        Validator::make(['environment' => $environment], [
            'environment' => ['present', 'nullable', 'string', 'max:65535'],
        ])->validate();

        $result = $this->shell->run($site->server, Script::render('sites.write-environment', [
            'site' => $site,
            'environment' => rtrim(str_replace("\r\n", "\n", $environment ?? '')),
            'delimiter' => 'SWAGE_ENV_'.Str::upper(Str::random(16)),
            'clearConfig' => $clearConfig,
        ]), timeout: 30);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->output));
        }
    }
}
