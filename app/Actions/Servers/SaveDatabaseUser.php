<?php

namespace App\Actions\Servers;

use App\Enums\ResourceStatus;
use App\Jobs\SyncDatabaseUser;
use App\Models\DatabaseUser;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveDatabaseUser
{
    /**
     * Create a database user, or change an existing one's password and databases, and sync it to the server.
     *
     * Accounts are synced by name and engine, so an existing user keeps both: changing either would leave the old
     * account behind with its access.
     *
     * @param  array{engine?: string, username?: string, password?: ?string, databases?: list<int>}  $input  A blank password keeps an existing user's.
     *
     * @throws ValidationException
     */
    public function handle(Server $server, ?DatabaseUser $user, array $input): DatabaseUser
    {
        $user ??= $server->databaseUsers()->make();

        if ($user->exists) {
            $input = [...$input, 'username' => $user->username, 'engine' => $user->engine->value];
        }

        $validated = Validator::make($input, [
            'engine' => AddDatabase::engineRules($server),
            'username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/D', 'not_regex:/^pg_/i', Rule::notIn(['root', 'postgres', config('swage.user')]),
                Rule::unique('database_users')->where('server_id', $server->id)->where('engine', $input['engine'] ?? null)->ignore($user->id)],
            'password' => [$user->exists ? 'nullable' : 'required', 'string', 'min:12', 'max:100', 'regex:/^[A-Za-z0-9!#%*+,.\/:=?@^_{}~-]+$/D'],
            'databases' => ['array'],
            // A user can only be given databases on its own engine.
            'databases.*' => [Rule::exists('databases', 'id')->where('server_id', $server->id)->where('engine', $input['engine'] ?? null)],
        ], [
            'username.not_regex' => __('Names starting with pg_ are reserved by PostgreSQL.'),
            'password.regex' => __('The password may not contain quotes, spaces, backslashes, dollar signs or backticks.'),
        ])->validate();

        $user->fill([
            'engine' => $validated['engine'],
            'username' => $validated['username'],
            'status' => ResourceStatus::Pending,
            ...(filled($validated['password'] ?? null) ? ['password' => $validated['password']] : []),
        ])->save();

        $user->databases()->sync($validated['databases'] ?? []);

        SyncDatabaseUser::dispatch($user);

        return $user;
    }
}
