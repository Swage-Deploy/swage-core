<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Models\Daemon;
use App\Models\Site;
use App\Sites\SiteCommands;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateQueueWorker
{
    /**
     * Start a queue worker for the site under Supervisor.
     *
     * @param  array{connection?: string, queue?: string, processes?: int, timeout?: int, sleep?: int, tries?: int}  $input
     *
     * @throws ValidationException
     */
    public function handle(Site $site, array $input): Daemon
    {
        $validated = Validator::make($input, [
            'connection' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'queue' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_,:.-]+$/D'],
            'processes' => ['required', 'integer', 'between:1,64'],
            'timeout' => ['required', 'integer', 'between:0,86400'],
            'sleep' => ['required', 'integer', 'between:0,3600'],
            'tries' => ['required', 'integer', 'between:0,100'],
        ])->validate();

        $options = [
            'connection' => $validated['connection'],
            'queue' => $validated['queue'],
            'sleep' => (int) $validated['sleep'],
            'tries' => (int) $validated['tries'],
            'timeout' => (int) $validated['timeout'],
        ];

        $daemon = $site->server->daemons()->create([
            'site_id' => $site->id,
            'command' => SiteCommands::queueWorker($site, $options),
            'directory' => $site->currentPath(),
            'user' => $site->systemUser(),
            'processes' => (int) $validated['processes'],
            'stop_wait_seconds' => $options['timeout'] + 10,
            'status' => ResourceStatus::Pending,
        ]);

        InstallDaemon::dispatch($daemon);

        return $daemon;
    }
}
