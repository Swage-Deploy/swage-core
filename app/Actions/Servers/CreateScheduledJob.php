<?php

namespace App\Actions\Servers;

use App\Enums\ResourceStatus;
use App\Jobs\InstallScheduledJob;
use App\Models\ScheduledJob;
use App\Models\Server;
use Closure;
use Cron\CronExpression;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateScheduledJob
{
    /**
     * Add a cron job to the server.
     *
     * @param  array{command?: string, user?: string, frequency?: string}  $input  The frequency is a cron expression.
     *
     * @throws ValidationException
     */
    public function handle(Server $server, array $input): ScheduledJob
    {
        $validated = Validator::make($input, [
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n%]/'],
            'user' => ['required', Rule::in($server->systemUsers())],
            'frequency' => ['required', 'string', 'max:100', fn (string $attribute, mixed $value, Closure $fail) => CronExpression::isValidExpression(trim((string) $value))
                ? null
                : $fail(__('Enter a valid cron expression.')),
            ],
        ], [
            'command.not_regex' => __('The command may not contain line breaks or % characters.'),
        ])->validate();

        $job = $server->scheduledJobs()->create([
            'command' => $validated['command'],
            'user' => $validated['user'],
            'frequency' => trim($validated['frequency']),
            'status' => ResourceStatus::Pending,
        ]);

        InstallScheduledJob::dispatch($job);

        return $job;
    }
}
