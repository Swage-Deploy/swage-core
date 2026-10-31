<?php

namespace App\Actions\Servers;

use App\Enums\PhpVersion;
use App\Jobs\InstallPhpVersion;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddPhpVersion
{
    /**
     * Start installing a PHP version the server doesn't have yet. Returns false when PHP or a database is already
     * being installed on the server, since both use apt and re-tune the server.
     *
     * @throws ValidationException
     */
    public function handle(Server $server, mixed $version): bool
    {
        abort_unless($server->isActive() && $server->isAppServer(), 409, __('PHP can only be added to an active app server.'));

        $validated = Validator::make(['version' => $version], [
            'version' => ['required', 'string', Rule::in(array_column($server->missingPhpVersions(), 'value'))],
        ], [
            'version.in' => __('Choose a supported PHP version the server doesn\'t have yet.'),
        ])->validate();

        $php = PhpVersion::from($validated['version']);

        $claimed = Server::query()->whereKey($server->id)->whereNull('installing_php')->whereNull('installing_database')->update(['installing_php' => $php->value]) === 1;

        $server->refresh();

        if ($claimed) {
            InstallPhpVersion::dispatch($server, $php);
        }

        return $claimed;
    }
}
