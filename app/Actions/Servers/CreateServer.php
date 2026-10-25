<?php

namespace App\Actions\Servers;

use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Models\Server;
use App\Models\User;
use App\Ssh\KeyPair;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

class CreateServer
{
    /**
     * Ranges PHP considers public that still reach shared or translated networks: carrier-grade NAT (which Tailscale
     * also uses), IETF protocol assignments, benchmarking, NAT64, 6to4 and Teredo. Each can lead back to the network
     * Swage runs in.
     *
     * @var list<string>
     */
    protected const array NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
        '2001::/32',
    ];

    /**
     * The databases a server of a type can be provisioned with. The other engine, or pgvector, can be installed later.
     *
     * @return array<string, string>
     */
    public static function databaseOptions(string $type): array
    {
        return [
            'mysql' => 'MySQL 8',
            'postgres' => 'PostgreSQL 16',
            'postgres-pgvector' => 'PostgreSQL 16 + pgvector',
            // A database server exists to run a database.
            ...($type === ServerType::Database->value ? [] : ['none' => __('None')]),
        ];
    }

    /**
     * Validate a server's details as the user entered them, then register it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function fromInput(User $user, array $input): Server
    {
        $type = (string) ($input['type'] ?? '');
        $isAppServer = $type === ServerType::App->value;

        $validated = Validator::make($input, [
            'type' => ['required', Rule::enum(ServerType::class)],
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'],
            // Only administrators may point Swage at its own host or the private network it runs in.
            'ip_address' => ['required', 'ip', fn (string $attribute, mixed $value, Closure $fail) => ! $user->is_admin
                && ! $this->isPublicAddress((string) $value)
                    ? $fail(__('Enter the server\'s public IP address.'))
                    : null,
            ],
            'private_ip_address' => ['nullable', 'ip', 'different:ip_address'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'php_versions' => $isAppServer ? ['required', 'array', 'min:1'] : ['exclude'],
            'php_versions.*' => $isAppServer ? [Rule::enum(PhpVersion::class)] : ['exclude'],
            'default_php_version' => $isAppServer ? ['required', Rule::in((array) ($input['php_versions'] ?? []))] : ['exclude'],
            'database' => ['required', Rule::in(array_keys(self::databaseOptions($type)))],
            'has_redis' => $isAppServer ? ['boolean'] : ['exclude'],
        ], [
            'name.regex' => __('The name may only contain letters, numbers, dots, dashes and underscores.'),
            'default_php_version.in' => __('The default PHP version must be one of the installed versions.'),
        ])->validate();

        $database = Arr::pull($validated, 'database');

        return $this->handle($user, [
            'php_versions' => [],
            'default_php_version' => null,
            ...$validated,
            'ssh_port' => (int) $validated['ssh_port'],
            'has_redis' => (bool) ($validated['has_redis'] ?? false),
            'private_ip_address' => ($validated['private_ip_address'] ?? null) ?: null,
            'has_mysql' => $database === 'mysql',
            'has_postgres' => str_starts_with($database, 'postgres'),
            'has_pgvector' => $database === 'postgres-pgvector',
        ]);
    }

    /**
     * Determine if an address is on the public internet, and not in a private, shared, reserved or tunnelled range
     * that could reach the network Swage runs in.
     */
    protected function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, self::NON_PUBLIC_RANGES);
    }

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
