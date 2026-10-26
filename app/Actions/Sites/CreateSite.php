<?php

namespace App\Actions\Sites;

use App\Deployments\DeployScript;
use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Jobs\InstallScheduledJob;
use App\Jobs\InstallSite;
use App\Jobs\SyncDatabaseUser;
use App\Models\Database;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteAliases;
use App\Sites\SiteCommands;
use App\Sites\SiteSystemUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateSite
{
    /**
     * Validate a site's details as the user entered them, then create it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function fromInput(Server $server, array $input): Site
    {
        $input['domain'] = strtolower(trim((string) ($input['domain'] ?? '')));
        $isolated = filter_var($input['isolated'] ?? false, FILTER_VALIDATE_BOOL);
        $engine = (string) ($input['database_engine'] ?? '');

        $validated = Validator::make($input, [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/D', Rule::unique('sites')->where('server_id', $server->id)],
            'aliases' => ['nullable', 'string', 'max:1000'],
            'web_directory' => ['required', 'string', 'max:100', 'regex:/^\/[A-Za-z0-9._\/-]*$/D', 'not_regex:/\.\./'],
            'php_version' => ['required', Rule::in($server->php_versions->map->value)],
            'isolated' => ['boolean'],
            'system_user' => ['exclude_unless:isolated,true', 'required', ...SiteSystemUser::rules($server)],
            'create_database' => ['boolean'],
            // Without isolation the database name doubles as its user's name, which MySQL caps at 32 characters.
            'database' => ['exclude_unless:create_database,true', 'required', 'string', 'max:'.($isolated ? 64 : 32), 'regex:/^[A-Za-z0-9_]+$/D', Database::notSystemDatabaseRule(),
                Rule::unique('databases', 'name')->where('server_id', $server->id)->where('engine', $engine)],
            'database_engine' => ['exclude_unless:create_database,true', 'required', Rule::in(array_column($server->databaseEngines(), 'value'))],
            'zero_downtime' => ['boolean'],
            'scheduler' => ['boolean'],
        ], [
            'domain.regex' => __('Enter a valid domain name, such as example.com.'),
            'system_user.regex' => __('Use lowercase letters, numbers and underscores, starting with a letter.'),
            'system_user.not_in' => __('This name belongs to a system account. Choose another.'),
            'system_user.unique' => __('Another site on this server already runs as this user.'),
            'database.unique' => __('A database with this name already exists on the server. Choose another.'),
            'database.not_regex' => __('This name is reserved by the database server. Choose another.'),
        ])->validate();

        $aliases = SiteAliases::parse($validated['aliases'] ?? null, $validated['domain']);
        SiteAliases::ensureAvailable($server, [$validated['domain']], 'domain');
        SiteAliases::ensureAvailable($server, $aliases, 'aliases');

        $createDatabase = filter_var($input['create_database'] ?? false, FILTER_VALIDATE_BOOL);

        $systemUser = $isolated ? $validated['system_user'] : null;
        $database = $createDatabase && $server->hasDatabase() ? $validated['database'] : null;
        $databaseEngine = $database !== null ? DatabaseEngine::from($validated['database_engine']) : null;

        if ($database !== null && ! $this->databaseUsernameIsAvailable($server, $systemUser ?? $database, $databaseEngine)) {
            throw ValidationException::withMessages([$isolated ? 'system_user' : 'database' => __('A database user with this name already exists on the server.')]);
        }

        return $this->handle($server, [
            'domain' => $validated['domain'],
            'system_user' => $systemUser,
            'aliases' => $aliases,
            'web_directory' => $validated['web_directory'],
            'php_version' => $validated['php_version'],
            'zero_downtime' => filter_var($validated['zero_downtime'] ?? false, FILTER_VALIDATE_BOOL),
            'database' => $database,
            'database_engine' => $databaseEngine,
            'scheduler' => filter_var($validated['scheduler'] ?? false, FILTER_VALIDATE_BOOL),
        ]);
    }

    /**
     * Determine if the site's database user can be created under a name on an engine.
     */
    protected function databaseUsernameIsAvailable(Server $server, string $username, DatabaseEngine $engine): bool
    {
        return ! in_array($username, ['root', 'postgres', config('swage.user')], true)
            && ! $server->databaseUsers()->where('engine', $engine)->where('username', $username)->exists();
    }

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
