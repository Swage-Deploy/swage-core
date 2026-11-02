<?php

namespace App\Actions\Servers;

use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Servers\AuthorizedKey;
use App\Servers\SshAccess;
use App\Ssh\Script;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddSshKey
{
    public function __construct(protected RunServerTask $runTask) {}

    /**
     * Add a public key to a user's authorized_keys on the server.
     *
     * @param  array{user?: string, name?: string, public_key?: string}  $input  Without a name, the key's comment is used.
     * @param  SshAccess  $access  What the server last reported, to tell when the key is already there.
     *
     * @throws ValidationException
     * @throws ScriptFailedException
     */
    public function handle(Server $server, array $input, SshAccess $access): AuthorizedKey
    {
        $key = AuthorizedKey::parse((string) ($input['public_key'] ?? ''));
        $key = $key && $key->options === '' ? $key : null;
        $name = trim((string) ($input['name'] ?? '')) ?: Str::of($key->comment ?? '')->replaceMatches('/[^A-Za-z0-9 ._@+-]+/', '')->trim()->limit(64, '')->toString();

        $validated = Validator::make([...$input, 'name' => $name], [
            'user' => ['required', Rule::in($server->sshUsers())],
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9 ._@+-]+$/D'],
            'public_key' => ['required', 'string', 'max:16000', function (string $attribute, mixed $value, Closure $fail) use ($key, $server, $access, $input): void {
                if ($key === null) {
                    $fail(__('Enter an OpenSSH public key, such as the contents of ~/.ssh/id_ed25519.pub.'));
                } elseif ($key->is($server->ssh_public_key)) {
                    $fail(__('This is Swage\'s own key for the server.'));
                } elseif (collect($access->keysFor((string) ($input['user'] ?? '')))->contains(fn (AuthorizedKey $existing) => $existing->data === $key->data)) {
                    $fail(__('This key can already sign in as this user.'));
                }
            }],
        ], [
            'name.regex' => __('The name may only contain letters, numbers, spaces and . _ @ + -'),
        ])->validate();

        $key = new AuthorizedKey($key->type, $key->data, $validated['name']);

        $this->runTask->run($server, "Adding SSH key {$key->comment} for {$validated['user']}", Script::render('ssh.add-key', [
            'sshUser' => $validated['user'],
            'key' => $key,
        ]), timeout: 30);

        return $key;
    }
}
