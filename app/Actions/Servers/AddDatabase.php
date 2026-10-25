<?php

namespace App\Actions\Servers;

use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddDatabase
{
    /**
     * Validation rules that keep an engine to the ones the server runs.
     *
     * @return list<mixed>
     */
    public static function engineRules(Server $server): array
    {
        return ['required', Rule::in(array_column($server->databaseEngines(), 'value'))];
    }

    /**
     * Create a database on one of the server's engines.
     *
     * @param  array{engine?: string, name?: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(Server $server, array $input): Database
    {
        $validated = Validator::make($input, [
            'engine' => self::engineRules($server),
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/D', Database::notSystemDatabaseRule(),
                Rule::unique('databases')->where('server_id', $server->id)->where('engine', $input['engine'] ?? null)],
        ], [
            'name.not_regex' => __('This name is reserved by the database server. Choose another.'),
        ])->validate();

        $database = $server->databases()->create([...$validated, 'status' => ResourceStatus::Pending]);

        CreateDatabase::dispatch($database);

        return $database;
    }
}
