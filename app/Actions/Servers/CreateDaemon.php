<?php

namespace App\Actions\Servers;

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Models\Daemon;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateDaemon
{
    /**
     * Start a long-running process on the server under Supervisor.
     *
     * @param  array{command?: string, directory?: ?string, user?: string, processes?: int, stop_wait_seconds?: int}  $input
     *
     * @throws ValidationException
     */
    public function handle(Server $server, array $input): Daemon
    {
        $validated = Validator::make($input, [
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'directory' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]*$/D'],
            'user' => ['required', Rule::in($server->systemUsers())],
            'processes' => ['required', 'integer', 'between:1,64'],
            'stop_wait_seconds' => ['required', 'integer', 'between:1,3600'],
        ])->validate();

        $daemon = $server->daemons()->create([...$validated, 'status' => ResourceStatus::Pending]);

        InstallDaemon::dispatch($daemon);

        return $daemon;
    }
}
