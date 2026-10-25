<?php

namespace App\Actions\Servers;

use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use App\Ssh\KeyPair;
use Illuminate\Support\Str;

class CreateServer
{
    /**
     * Register a new custom server awaiting provisioning.
     *
     * @param  array{type: string, name: string, ip_address: string, private_ip_address: ?string, ssh_port: int, php_versions: list<string>, default_php_version: ?string, has_mysql: bool, has_postgres: bool, has_pgvector: bool, has_redis: bool}  $attributes
     */
    public function handle(User $user, array $attributes): Server
    {
        $keys = KeyPair::generate("swage@{$attributes['name']}");

        return $user->servers()->create([
            ...$attributes,
            'php_versions' => collect($attributes['php_versions'])->map(fn (string $version) => PhpVersion::from($version)),
            'status' => ServerStatus::Pending,
            'provision_token' => Str::random(64),
            'ssh_private_key' => $keys->privateKey,
            'ssh_public_key' => $keys->publicKey,
            'database_password' => Str::password(32, symbols: false),
            'redis_password' => $attributes['has_redis'] ? Str::password(40, symbols: false) : null,
        ]);
    }
}
