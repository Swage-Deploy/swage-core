<?php

namespace App\Actions\Sites;

use App\Deployments\DeployScript;
use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Jobs\InstallScheduledJob;
use App\Jobs\InstallSite;
use App\Jobs\SyncDatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteCommands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSite
{
    /**
     * Create a site, optionally with its own database, database user and Laravel scheduler, and install it on the server.
     *
     * An isolated site's database user shares its system user's name; otherwise it is named after the database. The
     * database is always a new one, never one that already exists on the server.
     *
     * @param  array{domain: string, system_user: ?string, aliases: list<string>, web_directory: string, php_version: string, zero_downtime: bool, database: ?string, database_engine: ?DatabaseEngine, scheduler: bool}  $attributes
     */
    public function handle(Server $server, array $attributes): Site
    {
        // Every record is saved or none are, so a failure never leaves a site waiting on jobs that were never queued.
        [$site, $jobs] = DB::transaction(function () use ($server, $attributes): array {
            $site = $server->sites()->create([
                'domain' => $attributes['domain'],
                'system_user' => $attributes['system_user'] ?: null,
                'private_redis' => filled($attributes['system_user']) && $server->has_redis,
                'aliases' => $attributes['aliases'],
                'web_directory' => $attributes['web_directory'],
                'php_version' => $attributes['php_version'],
                'zero_downtime' => $attributes['zero_downtime'],
                'status' => ResourceStatus::Pending,
                'deploy_script' => DeployScript::default(),
                'deploy_token' => Str::random(48),
            ]);

            $jobs = [];
            $databaseName = null;
            $databaseUser = null;

            $engine = $attributes['database_engine'] ?? $server->defaultDatabaseEngine();

            if (filled($attributes['database']) && $engine !== null && $server->runsDatabaseEngine($engine)) {
                $databaseName = $attributes['database'];

                $database = $server->databases()->create([
                    'engine' => $engine,
                    'name' => $databaseName,
                    'status' => ResourceStatus::Pending,
                ]);

                $databaseUser = $server->databaseUsers()->create([
                    'engine' => $engine,
                    'username' => $site->system_user ?? $databaseName,
                    'password' => Str::random(40),
                    'status' => ResourceStatus::Pending,
                ]);

                $databaseUser->databases()->attach($database);

                $jobs[] = new CreateDatabase($database);
                $jobs[] = new SyncDatabaseUser($databaseUser);
            }

            $jobs[] = new InstallSite($site, $databaseName, $databaseUser);

            if ($attributes['scheduler']) {
                $jobs[] = new InstallScheduledJob($server->scheduledJobs()->create([
                    'site_id' => $site->id,
                    'command' => SiteCommands::scheduler($site),
                    'user' => $site->systemUser(),
                    'frequency' => '* * * * *',
                    'status' => ResourceStatus::Pending,
                ]));
            }

            return [$site, $jobs];
        });

        Bus::chain($jobs)
            ->catch(fn () => $site->update(['status' => ResourceStatus::Failed]))
            ->dispatch();

        return $site;
    }
}
