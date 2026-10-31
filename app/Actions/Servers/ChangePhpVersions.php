<?php

namespace App\Actions\Servers;

use App\Enums\PhpTask;
use App\Enums\PhpVersion;
use App\Jobs\ChangePhpVersion;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Installs, removes and changes the default of a server's PHP versions. Each returns false when PHP or a database is
 * already being changed on the server.
 */
class ChangePhpVersions
{
    /**
     * Start installing a supported PHP version the server doesn't have yet.
     *
     * @throws ValidationException
     */
    public function install(Server $server, mixed $version): bool
    {
        $php = $this->validated($server, $version, array_column($server->missingPhpVersions(), 'value'), __('Choose a supported PHP version the server doesn\'t have yet.'));

        return $this->start($server, PhpTask::Install, $php);
    }

    /**
     * Start making an installed PHP version the one `php` runs on the command line, whose PHP-FPM pool gets the
     * largest share of memory.
     *
     * @throws ValidationException
     */
    public function makeDefault(Server $server, mixed $version): bool
    {
        $php = $this->validated($server, $version, $this->otherVersions($server), __('Choose an installed PHP version other than the default.'));

        return $this->start($server, PhpTask::MakeDefault, $php);
    }

    /**
     * Start removing an installed PHP version that nothing on the server runs any more.
     *
     * @throws ValidationException
     */
    public function remove(Server $server, mixed $version): bool
    {
        $php = $this->validated($server, $version, $this->otherVersions($server), __('Choose an installed PHP version other than the default.'));

        if ($users = $server->phpVersionUsers($php)) {
            throw ValidationException::withMessages([
                'version' => __('PHP :version is still used by :users. Move them to another version first.', ['version' => $php->value, 'users' => implode(', ', $users)]),
            ]);
        }

        return $this->start($server, PhpTask::Remove, $php);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws ValidationException
     */
    protected function validated(Server $server, mixed $version, array $allowed, string $message): PhpVersion
    {
        abort_unless($server->isActive() && $server->isAppServer(), 409, __('PHP can only be changed on an active app server.'));

        $validated = Validator::make(['version' => $version], [
            'version' => ['required', 'string', Rule::in($allowed)],
        ], ['version.in' => $message])->validate();

        return PhpVersion::from($validated['version']);
    }

    /**
     * @return list<string>
     */
    protected function otherVersions(Server $server): array
    {
        return $server->php_versions->reject(fn (PhpVersion $php): bool => $php === $server->default_php_version)->map->value->values()->all();
    }

    protected function start(Server $server, PhpTask $task, PhpVersion $php): bool
    {
        if (! $server->claimPhpTask($task, $php)) {
            return false;
        }

        ChangePhpVersion::dispatch($server, $task, $php);

        return true;
    }
}
