<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallScheduledJob;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Site;
use App\Sites\SiteAliases;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateSiteSettings
{
    /**
     * Change the site's aliases, web directory and PHP version, and rewrite its config on the server.
     *
     * @param  array{aliases?: ?string, web_directory?: string, php_version?: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(Site $site, array $input): void
    {
        $validated = Validator::make($input, [
            'aliases' => ['nullable', 'string', 'max:1000'],
            'web_directory' => ['required', 'string', 'max:100', 'regex:/^\/[A-Za-z0-9._\/-]*$/D', 'not_regex:/\.\./'],
            'php_version' => ['required', Rule::in($site->server->php_versions->map->value)],
        ])->validate();

        $aliases = SiteAliases::parse($validated['aliases'] ?? null, $site->domain);
        $previousBinary = $site->php_version->binary();

        $site->update([
            'aliases' => $aliases,
            'web_directory' => $validated['web_directory'],
            'php_version' => $validated['php_version'],
            'status' => ResourceStatus::Installing,
        ]);

        UpdateSiteConfiguration::dispatch($site);

        if ($previousBinary !== $site->php_version->binary()) {
            $this->switchPhpBinary($site, $previousBinary, $site->php_version->binary());
        }
    }

    /**
     * Point the site's workers and scheduler at its new PHP version.
     */
    protected function switchPhpBinary(Site $site, string $from, string $to): void
    {
        foreach ($site->daemons as $daemon) {
            $daemon->update(['command' => preg_replace('/^'.preg_quote($from, '/').' /', "{$to} ", $daemon->command)]);
            InstallDaemon::dispatch($daemon);
        }

        foreach ($site->scheduledJobs as $scheduledJob) {
            $scheduledJob->update(['command' => preg_replace('/^'.preg_quote($from, '/').' /', "{$to} ", $scheduledJob->command)]);
            InstallScheduledJob::dispatch($scheduledJob);
        }
    }
}
